<?php

use App\Http\Controllers\DownloadGroupChatFile;
use App\Http\Controllers\ServeScreenFrame;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 | Attachment downloads.
 |
 | The legacy API listed attachments but served none, and its group chat download
 | script took a path with no membership check. The route below resolves a model and
 | delegates to a policy, so an id cannot be walked across accounts.
 |
 | There was a second route here for direct-message attachments. Direct messaging is
 | gone: group chat replaced it, because a teacher sharing a file or a block of code
 | with a class mid-lesson wants a room, not thirty one-to-one inboxes.
 */
Route::middleware('auth')->group(function () {
    Route::get('/group-chat/files/{message}/download', DownloadGroupChatFile::class)
        ->name('group-chat.files.download');

    /*
     | Live Screens.
     |
     | A teacher is served one PC's frame, never a list or a stream. Keeping it to a
     | single named route is what lets PcPolicy::viewScreen be the only thing that
     | decides visibility, and it means a teacher cannot obtain bytes for a PC
     | outside their class even by guessing a URL — the guess is refused before any
     | file is touched.
     */
    Route::get('/admin/monitoring/frames/{pc}', ServeScreenFrame::class)
        ->name('monitoring.frames.show');
});
