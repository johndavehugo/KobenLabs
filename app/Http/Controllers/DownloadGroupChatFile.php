<?php

namespace App\Http\Controllers;

use App\Models\GroupChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download a file shared in a group chat.
 *
 * `download-group-file.php` in the legacy app took a path and performed no
 * membership check, so any signed-in user could fetch any group's attachments by
 * guessing. Here the id is a model route parameter and GroupChatMessagePolicy
 * refuses anyone who cannot already read the message.
 */
class DownloadGroupChatFile extends Controller
{
    public function __invoke(Request $request, GroupChatMessage $message): StreamedResponse
    {
        Gate::authorize('download', $message);

        abort_unless($message->hasAttachment(), 404);

        $disk = Storage::disk((string) config('lab.storage.default_disk'));

        abort_unless($disk->exists((string) $message->file_path), 404, 'That file is no longer stored.');

        return $disk->download((string) $message->file_path, (string) $message->file_name);
    }
}