<?php

namespace App\Http\Controllers;

use App\Support\XenditRedirectUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class XenditPaymentReturnController extends Controller
{
    public function success(): Response|RedirectResponse
    {
        $bookingId = (int) request()->query('booking_id', 0);
        $bookingCode = trim((string) request()->query('booking_code', ''));
        $paymentType = $this->paymentType();
        $amountLabel = trim((string) request()->query('amount_label', ''));

        $appRedirect = $this->appRedirect($bookingId, $paymentType, ['verify' => '1']);

        if ($appRedirect) {
            return $appRedirect;
        }

        return $this->successPage($bookingId, $bookingCode, $paymentType, $amountLabel);
    }

    public function cancel(): Response|RedirectResponse
    {
        $bookingId = (int) request()->query('booking_id', 0);

        $appRedirect = $this->appRedirect($bookingId, $this->paymentType(), ['cancelled' => '1']);

        if ($appRedirect) {
            return $appRedirect;
        }

        return $this->page(
            'Checkout closed',
            'You can review your rental and try GCash checkout again when ready.',
            false,
            $bookingId,
            'downpayment',
        );
    }

    private function successPage(
        int $bookingId,
        string $bookingCode,
        string $paymentType,
        string $amountLabel,
    ): Response {
        $deeplink = $this->appDeeplink($bookingId, $paymentType);
        $submittedAt = now('Asia/Manila')->format('M d, Y · g:i A');
        $amountDisplay = $amountLabel !== '' ? $amountLabel : '—';
        $codeDisplay = $bookingCode !== '' ? $bookingCode : ($bookingId > 0 ? '#'.$bookingId : '—');
        $typeLabel = match ($paymentType) {
            'full_rental' => 'Full rental payment',
            'full_payment' => 'Remaining balance',
            default => 'Down payment',
        };

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payment successful · SafeSakay</title>
<style>
body{font-family:system-ui,sans-serif;margin:0;padding:1rem 1.25rem 2rem;background:#f4f7fb;color:#163a5f;line-height:1.5}
.card{max-width:26rem;margin:.5rem auto;padding:0;background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(26,90,215,.12);overflow:hidden}
.head{background:linear-gradient(135deg,#1a5ad7,#0f4c8a);color:#fff;padding:1.25rem 1.25rem 1rem;text-align:center}
.check{width:56px;height:56px;margin:0 auto .75rem;border-radius:50%;background:#1fa971;display:flex;align-items:center;justify-content:center;font-size:1.75rem;font-weight:800}
.head h1{margin:0;font-size:1.25rem;font-weight:800}
.head p{margin:.5rem 0 0;font-size:.85rem;opacity:.92}
.body{padding:1.1rem 1.25rem 1.25rem}
.amount{text-align:center;font-size:1.75rem;font-weight:900;color:#1a5ad7;margin:.25rem 0 1rem}
.row{display:flex;justify-content:space-between;gap:1rem;padding:.55rem 0;border-bottom:1px solid #eef3fa;font-size:.9rem}
.row span:first-child{color:#5a7088}
.row span:last-child{font-weight:700;text-align:right}
.btn{display:block;text-align:center;background:#1a5ad7;color:#fff;text-decoration:none;padding:.85rem 1rem;border-radius:12px;font-weight:700;margin-top:1rem;font-size:1rem}
.note{font-size:.78rem;color:#7d93a8;margin-top:.85rem;text-align:center;line-height:1.45}
.auto{margin-top:.65rem;font-size:.8rem;color:#1a5ad7;text-align:center}
</style>
</head>
<body>
<div class="card">
<div class="head">
<div class="check">✓</div>
<h1>Payment successful</h1>
<p>GCash payment sent to Xendit. Opening SafeSakay to show your receipt…</p>
</div>
<div class="body">
<div class="amount">{$this->e($amountDisplay)}</div>
<div class="row"><span>Booking reference</span><span>{$this->e($codeDisplay)}</span></div>
<div class="row"><span>Payment type</span><span>{$this->e($typeLabel)}</span></div>
<div class="row"><span>Submitted</span><span>{$this->e($submittedAt)}</span></div>
<a class="btn" id="open-app" href="{$this->e($deeplink)}">Back to SafeSakay</a>
<p class="auto" id="auto-msg">Returning to the app…</p>
<p class="note">Your booking is marked paid only after Xendit confirms with our server (usually a few seconds). The app will show transaction ID and receipt.</p>
</div>
</div>
<script>
(function () {
  var link = {$this->json($deeplink)};
  function openApp() {
    window.location.href = link;
  }
  setTimeout(openApp, 600);
  document.getElementById('open-app').addEventListener('click', function (e) {
    e.preventDefault();
    openApp();
  });
})();
</script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function page(
        string $title,
        string $body,
        bool $success,
        int $bookingId = 0,
        string $paymentType = 'downpayment',
    ): Response {
        $deeplink = $this->appDeeplink($bookingId, $paymentType);
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$this->e($title)} · SafeSakay</title>
<style>
body{font-family:system-ui,sans-serif;margin:0;padding:1.5rem;background:#f4f7fb;color:#163a5f;line-height:1.5}
.card{max-width:24rem;margin:1rem auto;padding:1.25rem;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h1{font-size:1.2rem;margin:0 0 .75rem;color:#0f4c8a}
p{margin:0 0 1rem;color:#4a6078;font-size:.95rem}
.btn{display:block;text-align:center;background:#1a5ad7;color:#fff;text-decoration:none;padding:.75rem 1rem;border-radius:10px;font-weight:600}
</style>
</head>
<body>
<div class="card">
<h1>{$this->e($title)}</h1>
<p>{$this->e($body)}</p>
<a class="btn" href="{$this->e($deeplink)}">Back to SafeSakay</a>
</div>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function paymentType(): string
    {
        $paymentType = trim((string) request()->query('payment_type', 'downpayment'));

        return in_array($paymentType, ['downpayment', 'full_rental', 'full_payment'], true)
            ? $paymentType
            : 'downpayment';
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function appRedirect(int $bookingId, string $paymentType, array $extra): ?RedirectResponse
    {
        $appReturn = XenditRedirectUrls::appReturn((string) request()->query('app_return', ''));

        if ($appReturn === null || $bookingId <= 0) {
            return null;
        }

        $query = http_build_query(['id' => (string) $bookingId, 'plan' => $paymentType] + $extra);

        return redirect()->away($appReturn.(str_contains($appReturn, '?') ? '&' : '?').$query);
    }

    private function appDeeplink(int $bookingId, string $paymentType): string
    {
        if ($bookingId <= 0) {
            return 'safesakay://xendit-checkout?verify=1&plan='.rawurlencode($paymentType);
        }

        return 'safesakay://xendit-checkout?'.http_build_query([
            'id' => (string) $bookingId,
            'verify' => '1',
            'plan' => $paymentType,
        ]);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function json(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
