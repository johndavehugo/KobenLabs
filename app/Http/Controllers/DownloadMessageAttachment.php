<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download a message attachment.
 *
 * The legacy API listed attachments on a message but exposed no route to fetch
 * one, so the feature was unusable. Authorisation is delegated entirely to
 * MessageAttachmentPolicy, which in turn defers to MessagePolicy: an attachment
 * can never be a way around the message's own visibility.
 */
class DownloadMessageAttachment extends Controller
{
    public function __invoke(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        abort_unless($attachment->existsOnDisk(), 404, 'That file is no longer stored.');

        return $attachment->disk()->download($attachment->path, $attachment->original_name);
    }
}