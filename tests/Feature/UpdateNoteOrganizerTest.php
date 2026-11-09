<?php

namespace Tests\Feature;

use App\Services\UpdateNoteOrganizer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UpdateNoteOrganizerTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = sys_get_temp_dir().'/cmo-notes-'.bin2hex(random_bytes(8));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    public function test_root_notes_are_moved_without_touching_other_files_and_reruns_are_safe(): void
    {
        file_put_contents($this->folder.'/UPDATE-v0.5.2.txt', 'original bytes');
        file_put_contents($this->folder.'/readme.md', 'fixed readme');
        $service = new UpdateNoteOrganizer;
        $r = $service->organize($this->folder);
        $this->assertSame(1, $r['moved']);
        $this->assertFileDoesNotExist($this->folder.'/UPDATE-v0.5.2.txt');
        $this->assertSame('original bytes', file_get_contents($this->folder.'/docs/updates/UPDATE-v0.5.2.txt'));
        $this->assertSame('fixed readme', file_get_contents($this->folder.'/readme.md'));
        $this->assertSame(0, $service->organize($this->folder)['moved']);
    }

    public function test_identical_root_and_destination_notes_are_deduplicated(): void
    {
        mkdir($this->folder.'/docs/updates', 0775, true);
        file_put_contents($this->folder.'/UPDATE-v0.5.2.txt', 'same');
        file_put_contents($this->folder.'/docs/updates/UPDATE-v0.5.2.txt', 'same');
        $r = (new UpdateNoteOrganizer)->organize($this->folder);
        $this->assertSame(1, $r['deduplicated']);
        $this->assertFileDoesNotExist($this->folder.'/UPDATE-v0.5.2.txt');
        $this->assertSame('same', file_get_contents($this->folder.'/docs/updates/UPDATE-v0.5.2.txt'));
    }

    public function test_different_existing_note_is_not_overwritten(): void
    {
        mkdir($this->folder.'/docs/updates', 0775, true);
        file_put_contents($this->folder.'/UPDATE-v0.5.2.txt', 'incoming');
        file_put_contents($this->folder.'/docs/updates/UPDATE-v0.5.2.txt', 'existing');
        $r = (new UpdateNoteOrganizer)->organize($this->folder);
        $this->assertSame(1, $r['moved']);
        $this->assertSame('existing', file_get_contents($this->folder.'/docs/updates/UPDATE-v0.5.2.txt'));
        $this->assertSame('incoming', file_get_contents($this->folder.'/docs/updates/UPDATE-v0.5.2-moved-1.txt'));
    }

    public function test_symlink_sources_are_skipped_and_symlink_destinations_are_rejected(): void
    {
        file_put_contents($this->folder.'/private.txt', 'untouched');
        symlink($this->folder.'/private.txt', $this->folder.'/UPDATE-v0.5.2.txt');
        $r = (new UpdateNoteOrganizer)->organize($this->folder);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame('untouched', file_get_contents($this->folder.'/private.txt'));
        rmdir($this->folder.'/docs/updates');
        symlink($this->folder, $this->folder.'/docs/updates');
        $this->expectException(\RuntimeException::class);
        (new UpdateNoteOrganizer)->organize($this->folder);
    }
}
