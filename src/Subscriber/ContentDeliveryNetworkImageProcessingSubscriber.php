<?php

declare(strict_types=1);

/*
 * This file is part of Ymir WordPress plugin.
 *
 * (c) Carl Alexander <support@ymirapp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ymir\Plugin\Subscriber;

use Ymir\Plugin\EventManagement\SubscriberInterface;
use Ymir\Plugin\Support\Collection;

/**
 * Subscriber that handles interaction with the content delivery network for image processing and optimization.
 */
class ContentDeliveryNetworkImageProcessingSubscriber implements SubscriberInterface
{
    /**
     * File extensions that we can process using the content delivery network.
     *
     * @var array
     */
    private const SUPPORTED_EXTENSIONS = ['gif', 'jpg', 'jpeg', 'png', 'webp'];

    /**
     * "content_width" global used to constrain image sizes if present.
     *
     * @see https://developer.wordpress.com/themes/content-width/
     *
     * @var int|null
     */
    private $contentWidthGlobal;

    /**
     * Flag whether the "image_downsize" filter is enabled or not.
     *
     * @var bool
     */
    private $imageDownsizeFilterEnabled;

    /**
     * The registered WordPress image sizes.
     *
     * @var array
     */
    private $imageSizes;

    /**
     * Flag whether this is a multisite installation or not.
     *
     * @var bool
     */
    private $isMultisite;

    /**
     * The URL to uploads directory.
     *
     * @var string
     */
    private $uploadsUrl;

    /**
     * {@inheritdoc}
     */
    public function __construct(array $imageSizes, bool $isMultisite, string $uploadsUrl, ?int $contentWidthGlobal = null)
    {
        $this->contentWidthGlobal = $contentWidthGlobal;
        $this->imageDownsizeFilterEnabled = true;
        $this->imageSizes = $imageSizes;
        $this->isMultisite = $isMultisite;
        $this->uploadsUrl = $uploadsUrl;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'image_downsize' => ['generateScaledDownImage', 10, 3],
            'the_content' => ['rewriteContentImageUrls', 999999],
            'get_post_galleries' => ['rewriteGalleryImageUrls', 999999],
            'rest_after_insert_attachment' => ['maybeDisableImageDownsizeFilterForInsertAttachmentRestRequest', 10, 2],
            'rest_request_after_callbacks' => 'reEnableImageDownsizeFilter',
            'rest_request_before_callbacks' => ['maybeDisableImageDownsizeFilterForRestRequest', 10, 3],
            'wp_calculate_image_srcset' => ['rewriteImageSrcset', 10, 5],
        ];
    }

    /**
     * Generate the scaled down image array for the given size.
     */
    public function generateScaledDownImage($image, $attachmentId, $size)
    {
        if (!$this->imageDownsizeFilterEnabled || is_admin() || !$this->isValidImageSize($size)) {
            return $image;
        }

        $imageUrl = wp_get_attachment_url($attachmentId);

        if (!is_string($imageUrl) || !$this->isProcessableImageUrl($imageUrl)) {
            return $image;
        }

        list($width, $height, $cropped) = $this->getAttachmentImageSize((int) $attachmentId, $size);

        return [
             $this->generateImageUrl($imageUrl, $height, $width, $cropped),
             $width ?? false,
             $height ?? false,
             'full' !== $size,
        ];
    }

    /**
     * Check the given REST API request for the given attachment and maybe disable "image_downsize" filter.
     */
    public function maybeDisableImageDownsizeFilterForInsertAttachmentRestRequest(\WP_Post $attachment, \WP_REST_Request $request)
    {
        $this->disableImageDownsizeFilterForEditMediaRestRequest($request);
    }

    /**
     * Check the given REST API request and maybe disable "image_downsize" filter.
     */
    public function maybeDisableImageDownsizeFilterForRestRequest($response, $handler, $request)
    {
        if ($request instanceof \WP_REST_Request && !$response instanceof \WP_Error) {
            $this->disableImageDownsizeFilterForEditMediaRestRequest($request);
        }

        return $response;
    }

    /**
     * Re-enable the "image_downsize" filter.
     */
    public function reEnableImageDownsizeFilter($response)
    {
        $this->imageDownsizeFilterEnabled = true;

        return $response;
    }

    /**
     * Rewrite the image URLs in the post content to use the content delivery network's image processing functionality.
     */
    public function rewriteContentImageUrls(string $content): string
    {
        $images = (new Collection($this->parseImagesFromHtml($content)))->filter(function (array $image) {
            return isset($image['image_tag'], $image['image_src']) && $this->isProcessableImageUrl($image['image_src']);
        });

        if ($images->isEmpty()) {
            return $content;
        }

        // Parse the attachment ID and add it to image array.
        $images = $images->map(function (array $image) {
            $matches = [];

            preg_match('#class=["|\']?[^"\']*wp-image-([\d]+)[^"\']*["|\']?#i', $image['image_tag'], $matches);

            if (!empty($matches[1]) && is_numeric($matches[1])) {
                $image['attachment_id'] = $matches[1];
            }

            return $image;
        });

        _prime_post_caches($images->map(function (array $image) {
            return $image['attachment_id'] ?? null;
        })->filter()->unique()->all(), false);

        /*
         * Pipeline for using the image collection and rewriting the image URLs.
         *
         *  1. Get the image size and add it to the array.
         *  2. Only keep images that we manage to determine a width or height for.
         *  3. Generate the before/after strings to use with "str_replace".
         *  4. Replace image URLs in the post content.
         */
        $images->map(function (array $image) {
            list($width, $height, $cropped) = $this->getImageSize($image);

            $image['cropped'] = $cropped;
            $image['height'] = $height;
            $image['width'] = $width;

            // Constrain dimension with "content_width" global if present.
            if (!$image['width'] || null === $this->contentWidthGlobal || $image['width'] <= $this->contentWidthGlobal) {
                return $image;
            }

            if ($image['height']) {
                $image['height'] = (int) round(($this->contentWidthGlobal / $image['width']) * $image['height']);
            }

            $image['width'] = $this->contentWidthGlobal;

            return $image;
        })->filter(function (array $image) {
            return $image['height'] || $image['width'];
        })->mapWithKeys(function (array $image) {
            $src = $this->getOriginalImageUrl($image['image_src']);
            $tag = str_replace($image['image_src'], esc_url($this->generateImageUrl($src, $image['height'], $image['width'], $image['cropped'])), $image['image_tag']);
            $tag = preg_replace('#(?<=\s)(height|width)=["|\']?[\d%]+["|\']?\s?#i', '', $tag);

            return [
                $image[0] => str_replace($image['image_tag'], $tag, $image[0]),
            ];
        })->each(function (string $newImageHtml, string $oldImageHtml) use (&$content) {
            $content = str_replace($oldImageHtml, $newImageHtml, $content);
        });

        return $content;
    }

    /**
     * Rewrite the image URLs in the galleries to use the content delivery network's image processing functionality.
     */
    public function rewriteGalleryImageUrls(array $galleries): array
    {
        foreach ($galleries as $index => $gallery) {
            $galleries[$index] = $this->rewriteContentImageUrls($gallery);
        }

        return $galleries;
    }

    /**
     * Rewrite the image URLs used by the srcset of an image.
     */
    public function rewriteImageSrcset($sources, $size, $imageSrc, $imageMetadata, $attachmentId)
    {
        return (new Collection($sources))->map(function (array $source) use ($attachmentId) {
            if (!isset($source['descriptor'], $source['value'], $source['url']) || 'w' !== $source['descriptor'] || !$this->isProcessableImageUrl($source['url'])) {
                return $source;
            }

            list($width, $height) = $this->parseImageDimensionsFromFilename($source['url']);

            if (!$height && !$width) {
                $width = $source['value'];
            }

            $url = is_numeric($attachmentId) ? wp_get_attachment_url($attachmentId) : $this->getOriginalImageUrl($source['url']);

            $source['url'] = $this->generateImageUrl($url, $height, $width);

            return $source;
        })->all();
    }

    /**
     * Try to determine the image size using WordPress functions and attachment data.
     */
    private function determineImageSizeUsingWordPressImageSizes(array $image): array
    {
        if (!isset($image['attachment_id'], $image['image_tag'])) {
            return [null, null, false];
        }

        $attachment = get_post($image['attachment_id']);

        if (!$attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type) {
            return [null, null, false];
        }

        $matches = [];
        $size = 'full';

        // Get the image size using the CSS class if present.
        if (preg_match('#class=["|\']?[^"\']*size-([^"\'\s]+)[^"\']*["|\']?#i', $image['image_tag'], $matches)) {
            $size = $matches[1];
        }

        $attachmentImage = wp_get_attachment_image_src($image['attachment_id'], $size);

        return isset($attachmentImage[0], $attachmentImage[1], $attachmentImage[2]) && $this->isProcessableImageUrl($attachmentImage[0]) ? [$attachmentImage[1], $attachmentImage[2], $this->isImageSizeCropped($size)] : [null, null, false];
    }

    /**
     * Disable the "image_downsize" filter if the WP_REST_Request is for the media endpoint in the edit context.
     */
    private function disableImageDownsizeFilterForEditMediaRestRequest(\WP_REST_Request $request)
    {
        if (false !== strpos($request->get_route(), 'wp/v2/media') && 'edit' === $request->get_param('context')) {
            $this->imageDownsizeFilterEnabled = false;
        }
    }

    /**
     * Generate the image URL for the content delivery network.
     */
    private function generateImageUrl(string $imageUrl, ?int $height, ?int $width, bool $cropped = false): string
    {
        $queryParameters = new Collection();

        if ($height) {
            $queryParameters['height'] = $height;
        }
        if ($width) {
            $queryParameters['width'] = $width;
        }
        if ($cropped) {
            $queryParameters['cropped'] = null;
        }

        return $queryParameters->isEmpty() ? $imageUrl : $imageUrl.rtrim($queryParameters->reduce(function ($carry, $value, $key) {
            return $carry.(empty($value) ? $key : $key.'='.$value).'&';
        }, '?'), '&');
    }

    /**
     * Get the image size array of the given image attachment for the given size.
     */
    private function getAttachmentImageSize(int $attachmentId, $size): array
    {
        $cropped = false;
        $fullSizeImageMetadata = wp_get_attachment_metadata($attachmentId);
        $height = null;
        $width = null;

        if (is_array($size)) {
            $height = isset($size[1]) ? (int) $size[1] : null;
            $width = isset($size[0]) ? (int) $size[0] : null;
        } elseif (is_int($size) || is_string($size)) {
            $imageMetadata = 'full' !== $size ? image_get_intermediate_size($attachmentId, $size) : $fullSizeImageMetadata;

            $cropped = $this->isImageSizeCropped($size);
            $height = isset($imageMetadata['height']) ? (int) $imageMetadata['height'] : null;
            $width = isset($imageMetadata['width']) ? (int) $imageMetadata['width'] : null;
        }

        // Make sure the calculated dimensions aren't larger than the full sized image dimensions.
        if (isset($fullSizeImageMetadata['height'], $fullSizeImageMetadata['width'])) {
            $resizedImageDimension = image_resize_dimensions($fullSizeImageMetadata['width'], $fullSizeImageMetadata['height'], $width, $height);

            $height = isset($resizedImageDimension[6], $resizedImageDimension[7]) ? (int) $resizedImageDimension[7] : $height;
            $width = isset($resizedImageDimension[6], $resizedImageDimension[7]) ? (int) $resizedImageDimension[6] : $width;
        }

        list($width, $height) = image_constrain_size_for_editor($width, $height, $size);

        return [$width, $height, $cropped];
    }

    /**
     * Get the image size array of the given image.
     */
    private function getImageSize(array $image): array
    {
        $height = null;
        $width = null;

        // Start by trying to parse the dimension from the <img> tag.
        if (isset($image['image_tag'])) {
            list($width, $height) = $this->parseImageDimensionsFromImgTagAttributes($image['image_tag']);
        }

        // Next try to get the image dimensions using the filename.
        if (isset($image['image_src']) && !$height && !$width) {
            list($width, $height) = $this->parseImageDimensionsFromFilename($image['image_src']);
        }

        return ($height || $width) ? [$width, $height, $height && $width] : $this->determineImageSizeUsingWordPressImageSizes($image);
    }

    /**
     * Get the original image URL from the re-dimensioned image URL.
     */
    private function getOriginalImageUrl($url)
    {
        return preg_replace(sprintf('#(-\d+x\d+)\.(%s)$#i', implode('|', self::SUPPORTED_EXTENSIONS)), '.\2', $url);
    }

    /**
     * Check if the given image size is cropped or not.
     */
    private function isImageSizeCropped($size): bool
    {
        if (!is_int($size) && !is_string($size)) {
            return false;
        }

        return (bool) $this->imageSizes[$size]['crop'] ?? false;
    }

    /**
     * Checks if we can have the content delivery network process the given image URL.
     */
    private function isProcessableImageUrl(string $imageUrl): bool
    {
        $path = parse_url($imageUrl, PHP_URL_PATH);

        if (!is_string($path) || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SUPPORTED_EXTENSIONS)) {
            return false;
        }

        $uploadsUrl = $this->uploadsUrl;

        if ($this->isMultisite) {
            $uploadsUrl = preg_replace('#/sites/[\d]+#', '', $uploadsUrl);
        }

        return 0 === strpos($imageUrl, $uploadsUrl);
    }

    /**
     * Checks if the given image size is valid.
     */
    private function isValidImageSize($size): bool
    {
        return is_array($size) || ((is_int($size) || is_string($size)) && array_key_exists($size, $this->imageSizes));
    }

    /**
     * Parse the dimension of an image using its filename.
     */
    private function parseImageDimensionsFromFilename(string $filename): array
    {
        $height = null;
        $matches = [];
        $width = null;

        if (preg_match(sprintf('#-(\d+)x(\d+)\.(?:%s)$#i', implode('|', self::SUPPORTED_EXTENSIONS)), $filename, $matches)) {
            $height = (int) $matches[2];
            $width = (int) $matches[1];
        }

        return [$width, $height];
    }

    /**
     * Parse the dimension of an image using the <img> tag attributes.
     */
    private function parseImageDimensionsFromImgTagAttributes(string $tag): array
    {
        $height = null;
        $matches = [];
        $width = null;

        if (preg_match('#height=["|\']?([\d]+)["|\']?#i', $tag, $matches)) {
            $height = (int) $matches[1];
        }
        if (preg_match('#width=["|\']?([\d]+)["|\']?#i', $tag, $matches)) {
            $width = (int) $matches[1];
        }

        return [$width, $height];
    }

    /**
     * Parse the given HTML and return all images.
     */
    private function parseImagesFromHtml(string $html): array
    {
        $images = [];

        if (!preg_match_all('#(?P<image_tag><img[^>]*?\s+?src=["|\'](?P<image_src>[^\s]+?)["|\'].*?>)#is', $html, $images, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($images as $index => $image) {
            $images[$index] = array_filter($image, function ($value, $key) {
                return 0 === $key || is_string($key);
            }, ARRAY_FILTER_USE_BOTH);
        }

        return $images;
    }
}
