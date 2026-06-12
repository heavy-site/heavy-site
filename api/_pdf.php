<?php
/* ───────────────────────────────────────────────────────────────
   PDF ticket generator (TCPDF). Produces a printable ticket per
   QR code, combining event data + buyer info + the verify QR.
   Returns the PDF as a binary string (for Resend attachments).
   Uses DejaVu Sans for full Cyrillic support.
─────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Build a single printable ticket PDF.
 *
 * @param array  $ticket  ticket record (id, token, index, of, name)
 * @param array  $order   order record (firstName, lastName, email)
 * @param array  $ev      event info (name, date, time, venue, address)
 * @param string $qrPng   QR code as raw PNG bytes (from make_qr_png)
 * @return string         PDF document as a binary string
 */
function make_ticket_pdf($ticket, $order, $ev, $qrPng) {
  // Page: 210 × 141 mm (A4 width, ticket height) — mirrors the old Node layout.
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
  $pdf->SetFillColor(0, 0, 0);
  $pdf->Rect(0, 0, $W, 22, 'F');
  $pdf->SetTextColor(255, 255, 255);
  $pdf->SetFont('dejavusans', 'B', 22);
  $pdf->SetXY(10, 5);
  $pdf->Cell(80, 12, 'HEAVY', 0, 0, 'L');
  $pdf->SetFont('dejavusans', '', 9);
  $pdf->SetTextColor(180, 180, 180);
  $pdf->SetXY($W - 70, 7);
  $pdf->Cell(60, 8, 'EVENT TICKET', 0, 0, 'R');

  // ── Event name ──
  $pdf->SetTextColor(0, 0, 0);
  $pdf->SetFont('dejavusans', 'B', 20);
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
    $pdf->SetTextColor(110, 110, 110);
    $pdf->SetXY(10, $y);
    $pdf->Cell(28, 6, $r[0], 0, 0, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(20, 20, 20);
    $pdf->SetXY(40, $y);
    $pdf->Cell(95, 6, $r[1], 0, 0, 'L');
    $y += 8;
  }

  // ── Dashed separator ──
  $sepX = $W - 68;
  $pdf->SetDrawColor(210, 210, 210);
  $pdf->SetLineStyle(['width' => 0.3, 'dash' => 2]);
  $pdf->Line($sepX, 24, $sepX, 131);
  $pdf->SetLineStyle(['dash' => 0]);

  // ── QR code ──
  if ($qrPng !== null && $qrPng !== '') {
    $qrSize = 50;
    $qrX = $sepX + ((68 - $qrSize) / 2);
    $pdf->Image('@' . $qrPng, $qrX, 32, $qrSize, $qrSize, 'PNG');
    $pdf->SetFont('dejavusans', 'B', 7);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->SetXY($sepX, 84);
    $pdf->Cell(68, 5, 'SCAN AT ENTRANCE', 0, 0, 'C');
  }

  // ── Footer ──
  $pdf->SetDrawColor(220, 220, 220);
  $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0]);
  $pdf->Line(10, 126, $sepX - 5, 126);
  $pdf->SetFont('dejavusans', '', 7);
  $pdf->SetTextColor(120, 120, 120);
  $pdf->SetXY(10, 128);
  $pdf->Cell(130, 5, 'Show this ticket (printed or on screen) at the entrance. Each ticket is valid once.', 0, 0, 'L');

  return $pdf->Output('ticket.pdf', 'S'); // 'S' = return as string
}
