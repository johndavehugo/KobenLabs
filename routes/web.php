<?php

use App\Http\Controllers\DownloadGroupChatFile;
use App\Http\Controllers\DownloadMessageAttachment;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Attachment downloads.
|
| The legacy API listed attachments but served none, and its group chat download
| script took a path with no membership check. Both routes below resolve a model
| and delegate to a policy, so an id cannot be walked across accounts.
*/
Route::middleware('auth')->group(function () {
    Route::get('/messages/attachments/{attachment}/download', DownloadMessageAttachment::class)
        ->name('messages.attachments.download');

    Route::get('/group-chat/files/{message}/download', DownloadGroupChatFile::class)
        ->name('group-chat.files.download');
});
