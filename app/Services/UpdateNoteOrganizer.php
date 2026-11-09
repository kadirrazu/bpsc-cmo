<?php

namespace App\Services;

use RuntimeException;

class UpdateNoteOrganizer
{
    public function organize(string $root): array
    {
        $root = realpath($root);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('Project root is unavailable.');
        }
        $docs = $root.'/docs';
        $destination = $docs.'/updates';
        if (is_link($docs) || is_link($destination)) {
            throw new RuntimeException('The docs/updates destination must be a real project directory.');
        }
        if (! is_dir($destination) && ! mkdir($destination, 0775, true) && ! is_dir($destination)) {
            throw new RuntimeException('Could not create docs/updates.');
        }
        $result = ['moved' => 0, 'deduplicated' => 0, 'skipped' => 0];
        foreach (glob($root.'/UPDATE-v*.txt') ?: [] as $source) {
            if (! is_file($source) || is_link($source)) {
                $result['skipped']++;

                continue;
            }
            $target = $destination.'/'.basename($source);
            if (file_exists($target) || is_link($target)) {
                if (is_file($target) && ! is_link($target) && hash_file('sha256', $source) === hash_file('sha256', $target)) {
                    if (! unlink($source)) {
                        throw new RuntimeException('Could not remove a duplicate root update note.');
                    }$result['deduplicated']++;

                    continue;
                }
                $stem = pathinfo(basename($source), PATHINFO_FILENAME);
                $number = 1;
                do {
                    $target = $destination.'/'.$stem.'-moved-'.$number++.'.txt';
                } while (file_exists($target) || is_link($target));
            }
            if (! rename($source, $target)) {
                throw new RuntimeException('Could not move an update note into docs/updates.');
            }$result['moved']++;
        }

        return $result;
    }
}
