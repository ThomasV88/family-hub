<?php

namespace App\Http\Controllers\Shopping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopping\MailListRequest;
use App\Shopping\Mail\ShoppingListMailer;
use App\Shopping\Queries\ListQuery;
use App\Shopping\ShoppingAccess;
use Illuminate\Http\RedirectResponse;

class MailController extends Controller
{
    public function __invoke(MailListRequest $request, int $list, ShoppingAccess $access, ShoppingListMailer $mailer): RedirectResponse
    {
        $user = $request->user();
        $model = ListQuery::visibleTo($user)->whereKey($list)->first();
        abort_if($model === null || ! $access->canView($user, $model), 404);
        abort_unless($access->canMail($user, $model), 403);

        $count = $mailer->send($user, $model, $request->validated()['addresses']);

        return back(303)->with('toast', ['key' => 'shopping.mail.toast_sent'])->with('flash_data', ['mail_sent_count' => $count]);
    }
}
