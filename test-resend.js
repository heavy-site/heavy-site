#!/usr/bin/env node
// Standalone test: verify Resend is working end-to-end with a PDF attachment.
// Usage: RESEND_API_KEY=re_xxx node test-resend.js recipient@example.com
require('dotenv').config();
const { Resend } = require('resend');
const { PDFDocument, StandardFonts, rgb } = require('pdf-lib');

const RESEND_API_KEY = process.env.RESEND_API_KEY;
const MAIL_FROM = process.env.MAIL_FROM || 'HEAVY <onboarding@resend.dev>';
const to = process.argv[2];

if (!to) { console.error('Usage: node test-resend.js <recipient-email>'); process.exit(1); }
if (!RESEND_API_KEY) { console.error('RESEND_API_KEY not set. Export it or add to .env'); process.exit(1); }

(async () => {
  console.log(`API key: ${RESEND_API_KEY.slice(0, 8)}…`);
  console.log(`From:    ${MAIL_FROM}`);
  console.log(`To:      ${to}`);

  const doc = await PDFDocument.create();
  const font = await doc.embedFont(StandardFonts.HelveticaBold);
  const page = doc.addPage([400, 200]);
  page.drawText('HEAVY — Test Ticket', { x: 50, y: 130, size: 20, font, color: rgb(0, 0, 0) });
  page.drawText('If you see this PDF, email delivery works.', {
    x: 50, y: 100, size: 10, font: await doc.embedFont(StandardFonts.Helvetica), color: rgb(0.3, 0.3, 0.3),
  });
  const pdfBuffer = Buffer.from(await doc.save());
  console.log(`PDF generated (${pdfBuffer.length} bytes)`);

  const resend = new Resend(RESEND_API_KEY);
  console.log('Sending via Resend…');
  const { data, error } = await resend.emails.send({
    from: MAIL_FROM,
    to: [to],
    subject: '[TEST] HEAVY Resend Integration',
    html: '<h2>Resend works!</h2><p>This test email includes a PDF attachment.</p>',
    attachments: [{ filename: 'test-ticket.pdf', content: pdfBuffer }],
  });

  if (error) {
    console.error('FAILED:', JSON.stringify(error, null, 2));
    process.exit(1);
  }
  console.log('SUCCESS — Resend ID:', data?.id);
})();
