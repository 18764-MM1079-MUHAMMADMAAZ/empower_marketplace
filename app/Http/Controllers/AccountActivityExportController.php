<?php

namespace App\Http\Controllers;

use App\Services\ClientActivity;
use App\Services\CompliancePdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** My Account › Activity Log › Export CSV / Export PDF: the client's list with the page's filters. */
class AccountActivityExportController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        $user = $request->user();
        abort_if($user->isAdmin(), 404);

        $type = array_key_exists((string) $request->query('type'), ClientActivity::TYPES) ? (string) $request->query('type') : 'all';
        $range = array_key_exists((string) $request->query('range'), ClientActivity::RANGES) ? (string) $request->query('range') : '7';
        $search = mb_substr((string) $request->query('search', ''), 0, 100);

        $activity = ClientActivity::for($user);
        $rows = $activity->query($type, $range, $search)->limit(5000)->get()
            ->values()
            ->map(fn ($log, $i) => ['sr' => $i + 1, ...$activity->present($log)]);
        $tz = ClientActivity::TIMEZONE === 'UTC' ? 'UTC' : now(ClientActivity::TIMEZONE)->format('T');
        $name = 'activity-log-'.now(ClientActivity::TIMEZONE)->format('Y-m-d');

        if ($format === 'csv') {
            $csv = fopen('php://temp', 'r+');
            fputcsv($csv, ['Sr. No.', "When ({$tz})", 'Activity', 'By', 'Device & location', 'Details', 'Type', 'Result']);

            foreach ($rows as $row) {
                fputcsv($csv, [
                    $row['sr'], $row['when']->format('Y-m-d H:i'), $row['title'], $row['actor'],
                    $row['where'], $row['detail'], $row['type_label'], $row['failed'] ? 'Failed' : '',
                ]);
            }

            rewind($csv);

            return response(stream_get_contents($csv), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$name}.csv\"",
            ]);
        }

        $html = view('account.activity-pdf', ['rows' => $rows, 'user' => $user, 'tz' => $tz])->render();

        $pdf = new CompliancePdf('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->setCreator(config('app.name'));
        $pdf->setPrintFooter(false);
        $pdf->headerTitle = config('app.name')." \u{2014} Activity Log";
        $pdf->headerLogoPath = public_path('images/logo-email.png');
        $pdf->setMargins(12, 26, 12);
        $pdf->setHeaderMargin(8);
        $pdf->setPrintHeader(true);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        return response((string) $pdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$name}.pdf\"",
        ]);
    }
}
