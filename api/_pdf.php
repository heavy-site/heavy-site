<?php
/* ───────────────────────────────────────────────────────────────
   PDF ticket generator (TCPDF). Produces a printable ticket with
   event poster image on the right side, event data + buyer info
   on the left. Returns the PDF as a binary string.
   Uses DejaVu Sans for full Cyrillic support.
─────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/vendor/autoload.php';

function make_ticket_pdf($ticket, $order, $ev, $qrPng) {
  @ini_set('memory_limit', '256M');

  $pdf = new \TCPDF('L', 'mm', [210, 141], true, 'UTF-8', false);
  $pdf->SetCreator('HEAVY');
  $pdf->SetAuthor('HEAVY');
  $pdf->SetTitle('Ticket — ' . ($ev['name'] ?? 'Event'));
  $pdf->SetMargins(0, 0, 0);
  $pdf->SetAutoPageBreak(false, 0);
  $pdf->setPrintHeader(false);
  $pdf->setPrintFooter(false);
  $pdf->AddPage();

  $W = 210;

  // ── Header bar (black) ──
  $pdf->SetFillColor(13, 13, 13);
  $pdf->Rect(0, 0, $W, 22, 'F');
  $pdf->SetTextColor(242, 242, 242);
  $pdf->SetFont('dejavusans', 'B', 22);
  $pdf->SetXY(10, 5);
  $pdf->Cell(80, 12, 'HEAVY', 0, 0, 'L');
  $pdf->SetFont('dejavusans', '', 9);
  $pdf->SetTextColor(140, 140, 140);
  $pdf->SetXY($W - 70, 7);
  $pdf->Cell(60, 8, 'EVENT TICKET', 0, 0, 'R');

  // ── Event name ──
  $pdf->SetTextColor(13, 13, 13);
  $pdf->SetFont('dejavusans', 'B', 18);
  $pdf->SetXY(10, 28);
  $pdf->Cell(130, 12, mb_strtoupper($ev['name'] ?? 'Event', 'UTF-8'), 0, 0, 'L');

  // ── Details ──
  $guest = trim((($order['firstName'] ?? '') . ' ' . ($order['lastName'] ?? '')));
  if ($guest === '') $guest = $ticket['name'] ?? '';
  $rows = [
    ['DATE',      $ev['date']    ?? ''],
    ['TIME',      $ev['time']    ?? ''],
    ['VENUE',     $ev['venue']   ?? ''],
    ['ADDRESS',   $ev['address'] ?? ''],
    ['GUEST',     $guest],
    ['EMAIL',     $order['email'] ?? ''],
    ['TICKET',    ($ticket['index'] ?? 1) . ' / ' . ($ticket['of'] ?? 1)],
    ['TICKET ID', strtoupper(substr((string)($ticket['id'] ?? ''), 0, 8))],
  ];
  $y = 46;
  foreach ($rows as $r) {
    if ($r[1] === '' || $r[1] === null) continue;
    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetTextColor(140, 140, 140);
    $pdf->SetXY(10, $y);
    $pdf->Cell(28, 6, $r[0], 0, 0, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(38, 38, 38);
    $pdf->SetXY(40, $y);
    $pdf->Cell(95, 6, $r[1], 0, 0, 'L');
    $y += 8;
  }

  // ── Dashed separator ──
  $sepX = $W - 68;
  $pdf->SetDrawColor(217, 217, 217);
  $pdf->SetLineStyle(['width' => 0.3, 'dash' => 2]);
  $pdf->Line($sepX, 24, $sepX, 131);
  $pdf->SetLineStyle(['dash' => 0]);

  // ── Event poster image (right side) ──
  $posterPath = __DIR__ . '/../event-poster.jpg';
  if (is_file($posterPath)) {
    $imgW = 58;
    $imgH = 100;
    $imgX = $sepX + ((68 - $imgW) / 2);
    $pdf->Image($posterPath, $imgX, 27, $imgW, $imgH, 'JPEG');
  }

  // ── Footer ──
  $pdf->SetDrawColor(217, 217, 217);
  $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0]);
  $pdf->Line(10, 126, $sepX - 5, 126);
  $pdf->SetFont('dejavusans', '', 7);
  $pdf->SetTextColor(140, 140, 140);
  $pdf->SetXY(10, 128);
  $pdf->Cell(130, 5, 'Show this ticket (printed or on screen) at the entrance. Each ticket is valid once.', 0, 0, 'L');

  return $pdf->Output('ticket.pdf', 'S');
}
