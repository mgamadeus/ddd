<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Imagick;
use ImagickException;

class PDFDocument extends Document
{
    /** @var string|null The type of the mediaitem */
    #[Choice(choices: [self::TYPE_PHOTO, self::TYPE_VIDEO, self::TYPE_DOCUMENT])]
    public ?string $type = self::TYPE_DOCUMENT;

    /** @var string The text content of the PDF */
    public string $textContent;

    /** @var string The title of the PDF */
    public string $title;

    /** @var GenericMediaItemContent PDF File Content */
    public GenericMediaItemContent $mediaItemContent;

    /**
     * Converts PDF to Image
     * @param int $width
     * @return PDFDocumentAsImage
     * @throws ImagickException
     */
    public function getDocumentAsImage(int $width = 1536): ?PDFDocumentAsImage
    {
        try {
            $imagick = new Imagick();
            $imagick->setResolution(300, 300); // You might need to adjust the resolution
            $imagick->readImageBlob($this->mediaItemContent->body);
            $imagick->setImageFormat('png');

            // Set the resolution and size
            $imagick->trimImage(0);

            // Optional: After trimming, you may want to add a small border to ensure no content is right at the edge
            $imagick->borderImage('white', 10, 10);

            $imagick->scaleImage($width, 0); // 0 for height makes it auto-scale

            // Get image blob
            $pdfDocumentAsImage = new PDFDocumentAsImage();
            $pdfDocumentAsImage->mediaItemContent = new PDFMediaItemContent();
            $pdfDocumentAsImage->mediaItemContent->populateMediaItemContentInfoFromImagick($imagick);
            $pdfDocumentAsImage->addChildren($pdfDocumentAsImage->mediaItemContent);

            // Cleanup
            $imagick->clear();
            $imagick->destroy();

            return $pdfDocumentAsImage;
        } catch (InternalErrorException $e) {
            error_log('Error converting PDF to image: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return string Extracts text content from PDF
     */
    public function getTextContent(): string
    {
        if (isset($this->textContent)) {
            return $this->textContent;
        }
        if (!$this->mediaItemContent->getBody()) {
            return '';
        }

        // Create a temporary file for the PDF
        $tempPdfPath = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tempPdfPath, $this->mediaItemContent->getBody());

        // Attempt to extract text using pdftotext
        $tempTxtPath = tempnam(sys_get_temp_dir(), 'txt');
        $command = "pdftotext \"$tempPdfPath\" \"$tempTxtPath\"";
        exec($command);
        $extractedText = file_get_contents($tempTxtPath);
        // If text extraction was successful, no need to proceed with OCR
        if (strlen(trim($extractedText)) > 50) {
            unlink($tempPdfPath);
            unlink($tempTxtPath);
            $extractedText = preg_replace('/\.{4,}/', ' ', $extractedText);
            return $extractedText;
        }

        // Setup for OCR: Convert PDF to images
        $imagesDir = sys_get_temp_dir() . '/pdf_images_' . uniqid();
        mkdir($imagesDir);
        exec("magick convert -density 300 \"$tempPdfPath\" \"$imagesDir/image-%d.png\"", $output);
        $test = file_get_contents($tempPdfPath);
        $images = glob("$imagesDir/*.png");
        $ocrOutputFiles = [];
        foreach ($images as $index => $imagePath) {
            $outputPath = "$imagesDir/output-$index";
            $ocrOutputFiles[] = $outputPath . '.txt';
            // Execute Tesseract OCR in the background
            $command = "tesseract \"$imagePath\" \"$outputPath\" > /dev/null 2>&1 &";
            exec("tesseract \"$imagePath\" \"$outputPath\" > /dev/null 2>&1 &");
        }

        // Implement a timeout mechanism
        $startTime = time();
        $timeoutSeconds = 10;
        $allDone = false;

        while (!$allDone && (time() - $startTime) < $timeoutSeconds) {
            $allDone = true;
            foreach ($ocrOutputFiles as $outputFile) {
                if (!file_exists($outputFile) || filesize($outputFile) === 0) {
                    $allDone = false;
                    usleep(100000); // Wait for 100 milliseconds before checking again
                    break;
                }
            }
        }

        // Check if the loop exited due to timeout
        if (!$allDone) {
            // Handle timeout scenario, e.g., log an error, clean up
            array_map('unlink', $ocrOutputFiles);
            array_map('unlink', glob("$imagesDir/*.png"));
            rmdir($imagesDir);
            unlink($tempPdfPath);
            return '';
        }

        // Sort the output files based on sequence numbers to ensure correct order
        usort($ocrOutputFiles, function ($a, $b) {
            return intval(basename($a, '.txt')) - intval(basename($b, '.txt'));
        });

        // Combine OCR results in the original order
        $combinedText = '';
        foreach ($ocrOutputFiles as $outputFile) {
            $combinedText .= file_get_contents($outputFile) . "\n";
            unlink($outputFile); // Clean up
        }

        // Clean up
        array_map('unlink', glob("$imagesDir/*.png"));
        rmdir($imagesDir);
        unlink($tempPdfPath);
        if (file_exists($tempTxtPath)) {
            unlink($tempTxtPath);
        }

        $combinedText = preg_replace('/\.{4,}/', ' ', $combinedText);
        return $combinedText;
    }


    public static function fromHTML(
        string $html,
        ?string $author = null,
        ?string $title = null,
        ?DateTime $createdDateTime = null,
        ?DateTime $modifiedDateTime = null
    ): PDFDocument {
        // Set cache path
        $cachePath = DDDService::instance()->getCacheDir(false) . '/dompdf_cache';

        // Check if the cache directory exists, if not create it
        if (!file_exists($cachePath)) {
            mkdir($cachePath, 0775, true);
        }

        // Create an instance of the Dompdf class
        $dompdf = new Dompdf(['enable_remote' => true]);
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultMediaType', true);
        $options->set('fontDir', $cachePath);
        $options->set('fontCache', $cachePath);
        $options->set('tempDir', $cachePath);
        $options->set('chroot', $cachePath);
        /*$options->set('debugKeepTemp', true);
        $options->set('debugCss', true);
        $options->set('debugLayout', true);
        $options->set('debugLayoutLines', true);
        $options->set('debugLayoutBlocks', true);
        $options->set('debugLayoutInline', true);
        $options->set('debugLayoutPaddingBox', true);*/


        // Set cache directory in Dompdf options
        $pdfDocument = new static();

        $dompdf->setOptions($options);
        $dompdf->setPaper('A4', 'portrait');

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $dompdf->addInfo('Creator', 'Word');
        if ($createdDateTime) {
            $dompdf->addInfo('CreationDate', 'D:' . $createdDateTime->format('YmdHis'));
            $pdfDocument->createdDateTime = $createdDateTime;
        }
        if ($modifiedDateTime) {
            $pdfDocument->modifiedDateTime = $modifiedDateTime;
        }
        if ($author) {
            $dompdf->addInfo('Author', $author);
        }
        if ($title) {
            $dompdf->addInfo('Title', $title);
        }

        $pdfDocument->mediaItemContent = new GenericMediaItemContent();
        $pdfDocument->addChildren($pdfDocument->mediaItemContent);
        if ($title) {
            $pdfDocument->title = $title;
        }
        $pdfDocument->mediaItemContent->body = $dompdf->output();
        return $pdfDocument;
    }

    public function getFileName(): ?string
    {
        $fileName = null;

        if (isset($this->fileName)) {
            $fileName = $this->fileName;
        } elseif (isset($this->title)) {
            $fileName = $this->title;
        }

        // Check if the fileName is set and does not end with '.pdf'
        if ($fileName !== null && substr($fileName, -4) !== '.pdf') {
            $fileName .= '.pdf'; // Add '.pdf' to the end
        }

        return $fileName;
    }

    public function download(): void
    {
        // Set headers to force download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $this->getFileName() .'"');
        header('Content-Length: ' . strlen($this->mediaItemContent->body));

        // Output the PDF content
        echo $this->mediaItemContent->body;
        exit;
    }
}
