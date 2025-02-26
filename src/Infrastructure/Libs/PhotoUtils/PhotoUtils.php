<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs\PhotoUtils;

use DDD\Infrastructure\Exceptions\InternalErrorException;
use Exception;
use Imagick;
use ImagickException;

class PhotoUtils
{
    public const SUPPORTED_FORMATS = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF];

    /**
     * Returns the file mime type from a base64
     * @param string $base64
     * @return string
     */
    public static function getFileMimeTypeFromBase64(string $base64): string
    {
        $decoded = base64_decode($base64);

        if (!$decoded) {
            return 'unknown';
        }

        $byteArray = unpack('C*', substr($decoded, 0, 4));

        if ($byteArray[1] == 0xFF && $byteArray[2] == 0xD8) {
            return 'image/jpeg';
        }

        if ($byteArray[1] == 0x89 && $byteArray[2] == 0x50 && $byteArray[3] == 0x4E && $byteArray[4] == 0x47) {
            return 'image/png';
        }

        if ($byteArray[1] == 0x47 && $byteArray[2] == 0x49 && $byteArray[3] == 0x46) {
            return 'image/gif';
        }

        if ($byteArray[1] == 0x25 && $byteArray[2] == 0x50 && $byteArray[3] == 0x44 && $byteArray[4] == 0x46) {
            return 'application/pdf';
        }

        return 'unknown';
    }

    public static function getImageInfoFromString(string $imageBlob): ?array
    {
        $imageInfo = getimagesizefromstring($imageBlob);
        if (!$imageInfo) {
            return null;
        }

        return $imageInfo;
    }

    public static function getImageWidthAndHeightFromString(string $imageBlob): ?array
    {
        $imageInfo = self::getImageInfoFromString($imageBlob);
        if (!$imageInfo) {
            return null;
        }
        $widthAndHeightData = explode('"', $imageInfo[3]);
        return [(int)$widthAndHeightData[1], (int)$widthAndHeightData[3]];
    }

    public static function getNumbersFromString(string $message): array
    {
        preg_match_all('/\d+/', $message, $matches);
        return $matches[0];
    }


    /**
     * @param string $base64Image
     * @param int $minWidth
     * @param int $minHeight
     * @param int $maxWidth
     * @param int $maxHeight
     * @return string
     * @throws ImagickException
     */
    public static function adjustImageToRequriements(
        string $imageBlob,
        int $minWidth,
        int $minHeight,
        int $maxWidth,
        int $maxHeight,
        ?float $aspectRatio = null,
        ?float $aspectRatioTolerance = null,
        ?int $minSizeInBytes = null,
        ?int $maxSizeInBytes = null,
        array $supportedFormats = ['png', 'jpg', 'jpeg'],
    ): ?string {
        try {
            // Create an Imagick object from the image content
            $imagick = new Imagick();
            $imagick->readImageBlob($imageBlob);

            if ($imagick->getImageColorspace() == Imagick::COLORSPACE_CMYK) {
                $cmykProfilePath = __DIR__ . '/Resources/USWebCoatedSWOP.icc';
                $rgbProfilePath = __DIR__ . '/Resources/sRGB_v4_ICC_preference.icc';

                // Make sure the profiles exist before attempting to use them
                if (file_exists($cmykProfilePath) && file_exists($rgbProfilePath)) {
                    $cmykProfile = file_get_contents($cmykProfilePath);
                    $imagick->profileImage('icc', $cmykProfile);

                    $rgbProfile = file_get_contents($rgbProfilePath);
                    $imagick->profileImage('icc', $rgbProfile);
                } else {
                    // Handle the error appropriately if the profiles are not found
                    // For example, throw an exception or log an error message
                    throw new InternalErrorException('ICC profiles are missing.');
                }
            }

            // Get the image format
            $format = strtolower($imagick->getImageFormat());

            // If the format is not JPEG or PNG, convert it to JPEG
            if (!in_array($format, $supportedFormats)) {
                $imagick->setImageFormat('jpg');
            }
            // Check if the image has multiple frames
            if ($imagick->getNumberImages() > 1) {
                // Set the first frame as the active frame
                $imagick->setIteratorIndex(0);

                // Create a new Imagick object with just the first frame
                $singleFrameImagick = new Imagick();
                $singleFrameImagick->addImage($imagick->getImage());
                $imagick = $singleFrameImagick;
            }

            // Get the original image dimensions
            $originalWidth = $imagick->getImageWidth();
            $originalHeight = $imagick->getImageHeight();

            // Calculate the new dimensions based on the minimum and maximum width and height
            $newWidth = $originalWidth;
            $newHeight = $originalHeight;

            $resize = false;
            if ($originalWidth < $minWidth || $originalHeight < $minHeight) {
                $ratio = max($minWidth / $originalWidth, $minHeight / $originalHeight);
                $newWidth = (int)($originalWidth * $ratio);
                $newHeight = (int)($originalHeight * $ratio);
                $resize = true;
            } elseif ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
                $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
                $newWidth = (int)($originalWidth * $ratio);
                $newHeight = (int)($originalHeight * $ratio);
                $resize = true;
            }

            // Apply the aspect ratio by cropping the image if the difference is greater than 0.1%
            if ($aspectRatio !== null && $aspectRatioTolerance) {
                $currentAspectRatio = $newWidth / $newHeight;
                $aspectRatioDifference = abs(($currentAspectRatio - $aspectRatio) / $aspectRatio);

                if ($aspectRatioDifference > $aspectRatioTolerance) {
                    if ($currentAspectRatio > $aspectRatio) {
                        $cropWidth = (int)($newHeight * $aspectRatio);
                        $cropHeight = $newHeight;
                    } else {
                        $cropWidth = $newWidth;
                        $cropHeight = (int)($newWidth / $aspectRatio);
                    }
                    $x = (int)(($newWidth - $cropWidth) / 2);
                    $y = (int)(($newHeight - $cropHeight) / 2);
                    $imagick->cropImage($cropWidth, $cropHeight, $x, $y);
                    $newWidth = $cropWidth;
                    $newHeight = $cropHeight;
                    $resize = true;
                }
            }

            if ($resize) {
                // Resize the image
                $imagick->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);
            }

            // Check the image size
            $currentImageSize = strlen($imagick->getImageBlob());

            // Upscale the image if the size is smaller than minSizeInBytes
            if ($minSizeInBytes !== null && $currentImageSize < $minSizeInBytes) {
                $scaleFactor = sqrt($minSizeInBytes / $currentImageSize * 2);
                $newWidth = (int)($newWidth * $scaleFactor);
                $newHeight = (int)($newHeight * $scaleFactor);
                $imagick->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 1);
            }
            // Convert to JPEG and reduce quality if the size is larger than maxSizeInBytes
            if ($maxSizeInBytes !== null && strlen($imagick->getImageBlob()) > $maxSizeInBytes) {
                $imagick->setImageFormat('jpg');
                $imagick->setImageCompressionQuality(90);
            }
            if (in_array(strtolower($imagick->getImageFormat()), ['jpg', 'jpeg'])) {
                $imagick->setImageCompressionQuality(85);
            }

            // Get the final image content
            $finalImageContent = $imagick->getImageBlob();

            // Cleanup
            $imagick->clear();
            $imagick->destroy();

            return $finalImageContent;
        } catch (ImagickException $e) {
            // Handle the exception, e.g., log the error or return null
            return $imageBlob;
            return null;
        }
    }

    public static function getLocationIdAndIdentifierForMediaItemFromRequestParams(array $requestParams): ?array
    {
        if (!($requestParams['file'] ?? null)) {
            return null;
        }
        $locationIdAndMediaItemIdentifierArray = explode('/', $requestParams['file']);
        if (count($locationIdAndMediaItemIdentifierArray) !== 2) {
            return null;
        }
        return $locationIdAndMediaItemIdentifierArray;
    }

    public static function getImageBase64FromUrl(string $imageUrl): ?string
    {
        $imageContent = file_get_contents($imageUrl);

        if ($imageContent === false) {
            return null;
        }

        return base64_encode($imageContent);
    }

    /**
     * @throws Exception
     */
    public static function generateUniqueMediaItemIdentifier(string|int $locationId, ?string $photoName = null): string
    {
        $currentTime = microtime(true);
        $randomNumber = random_int(0, 1000000);

        return md5($photoName . $locationId . $currentTime . $randomNumber);
    }

    /**
     * @param string $base64EncodedImage
     * @return string|null
     */
    public static function getEncodedImageString(string $base64EncodedImage): ?string
    {
        return explode(',', $base64EncodedImage)[1];
    }

    public static function isUrl($url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        $encoded_path = array_map('urlencode', explode('/', $path));
        $url = str_replace($path, implode('/', $encoded_path), $url);

        return (bool)filter_var($url, FILTER_VALIDATE_URL);
    }
}