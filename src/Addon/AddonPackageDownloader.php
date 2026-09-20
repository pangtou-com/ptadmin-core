<?php

declare(strict_types=1);

namespace PTAdmin\Addon;

use RuntimeException;

final class AddonPackageDownloader
{
    /** @return array{artifact:string,sha256:string,size:int} */
    public function download(string $source, string $destination, ?string $expectedSha256 = null): array
    {
        if ($source === '') {
            throw new AddonManifestException('The addon download source is unavailable.', 'download_source_unavailable');
        }
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create addon artifact directory.');
        }
        $temporary = $destination.'.tmp-'.bin2hex(random_bytes(5));
        $input = $this->openSource($source);
        $output = fopen($temporary, 'wb');
        if (false === $output) {
            if (is_resource($input)) {
                fclose($input);
            }
            throw new RuntimeException('Unable to create addon artifact.');
        }
        try {
            if (false === stream_copy_to_stream($input, $output)) {
                throw new RuntimeException('Unable to download addon artifact.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        $sha256 = hash_file('sha256', $temporary);
        if (!is_string($sha256)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to hash addon artifact.');
        }
        if (is_string($expectedSha256) && $expectedSha256 !== '') {
            if (preg_match('/^[a-f0-9]{64}$/i', $expectedSha256) !== 1 || !hash_equals(strtolower($expectedSha256), $sha256)) {
                @unlink($temporary);
                throw new AddonManifestException('The addon artifact checksum is invalid.', 'checksum_mismatch');
            }
        }
        if (!rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to activate addon artifact.');
        }
        @chmod($destination, 0600);
        return ['artifact' => $destination, 'sha256' => $sha256, 'size' => (int) filesize($destination)];
    }

    /** @return resource */
    private function openSource(string $source)
    {
        $local = realpath($source);
        if (is_string($local) && is_file($local)) {
            $stream = fopen($local, 'rb');
        } elseif (filter_var($source, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($source, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $stream = @fopen($source, 'rb', false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 0, 'user_agent' => 'PTAdmin-Core']]));
        } else {
            $stream = false;
        }
        if (false === $stream) {
            throw new AddonManifestException('The addon download source is unavailable.', 'download_source_unavailable');
        }
        return $stream;
    }
}
