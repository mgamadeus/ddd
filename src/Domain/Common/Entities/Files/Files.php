<?php

namespace DDD\Domain\Common\Entities\Files;

use DDD\Domain\Base\Entities\ObjectSet;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Holds a set of files
 * @property File[] $elements
 * @method File[] getElements()
 * @method File first()
 */
class Files extends ObjectSet
{
    public function __construct(array $uploadedFiles = [])
    {
        parent::__construct();

        $this->build($uploadedFiles);
    }

    public function build(array $uploadedFiles): Files
    {
        /** @var UploadedFile $uploadedFile */
        foreach ($uploadedFiles as $uploadedFile) {
            $file = new File();
            $file->originalName = $uploadedFile->getClientOriginalName();
            $file->mimeType = $uploadedFile->getClientMimeType();
            $file->originalPath = $uploadedFile->getClientOriginalPath();
            $file->path = $uploadedFile->getPathname();
            $this->add($file);
        }

        return $this;
    }
}
