<?php

namespace App\Http\Controllers;

use App\Models\PaymentLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** A receipt for one successful charge (first payment, renewal…) from My Account's billing history. */
class PaymentReceiptController extends Controller
{
    public function show(Request $request, PaymentLog $paymentLog): View
    {
        abort_unless($paymentLog->user_id === $request->user()->id, 403);
        abort_unless($paymentLog->success, 404, 'There is no receipt for a declined payment.');

        $paymentLog->loadMissing(['package', 'order', 'user.practice']);

        return view('receipts.payment', [
            'payment' => $paymentLog,
            'order' => $paymentLog->order,
            'practice' => $paymentLog->user->practice,
        ]);
    }
}
