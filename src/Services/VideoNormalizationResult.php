<?php

namespace Iquesters\SmartMessenger\Services;

class VideoNormalizationResult
{
    public function __construct(
        public readonly string $outputPath,
        public readonly string $mimeType,
        public readonly string $tempDir,
    ) {}
}
