import { downloadPdfBytes } from '@/lib/reportExport';
import type { BoatBookingRecord } from '@/types/booking';

type LogoImage = {
    bytes: Uint8Array;
    width: number;
    height: number;
};

function logoUrl(): string {
    if (typeof window === 'undefined') {
        return '/images/safesakay-logo.png?v=2';
    }

    return `${window.location.origin}/images/safesakay-logo.png?v=2`;
}

function slipFilename(code: string): string {
    return `SafeSakay-Rental-Slip-${code.replace(/[^A-Za-z0-9_-]/g, '_')}.pdf`;
}

function displayText(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value).replaceAll('₱', 'PHP ').replaceAll('\n', ' ');
}

function detailRows(booking: BoatBookingRecord): Array<{ label: string; value: string }> {
    const operator = [booking.boat.operator_name, booking.boat.operator_contact_number]
        .filter(Boolean)
        .join(' · ');

    const rows: Array<{ label: string; value: string }> = [
        { label: 'Booked by', value: booking.customer_name },
        { label: 'Contact Number', value: displayText(booking.contact_number) },
        { label: 'Boat', value: `${booking.boat.name} (${booking.boat.code})` },
        { label: 'Rental Date', value: booking.booking_date_label },
        { label: 'Preferred Departure Time', value: booking.start_time_label },
        { label: 'Destination', value: booking.destination },
        { label: 'Pickup / Meeting Point', value: displayText(booking.pickup_point) },
        { label: 'Number of Passengers', value: String(booking.passenger_count) },
        { label: 'Purpose of trip', value: displayText(booking.purpose) },
        { label: 'Additional notes', value: displayText(booking.remarks) },
    ];

    if (operator) {
        rows.push({ label: 'Boat Operator', value: operator });
    }

    return rows;
}

function paymentRows(booking: BoatBookingRecord): Array<{ label: string; value: string }> {
    const payment = booking.payment;
    if (!payment) {
        return [];
    }

    const rows: Array<{ label: string; value: string }> = [
        { label: 'Total Rental Price', value: displayText(payment.rental_price_label) },
        { label: 'Down Payment', value: displayText(payment.down_payment_amount_label) },
        { label: 'Down Payment Status', value: displayText(payment.payment_status_label) },
    ];

    if (payment.commission_enabled) {
        rows.push({
            label: 'Included barangay commission',
            value: displayText(
                [payment.commission_amount_label, payment.commission_percentage_label]
                    .filter(Boolean)
                    .join(' '),
            ),
        });
    }

    if (payment.gcash_reference) {
        rows.push({
            label: 'GCash Reference Number',
            value: displayText(payment.gcash_reference),
        });
    }

    if (payment.payment_submitted_at_label) {
        rows.push({
            label: 'Payment Submitted',
            value: payment.payment_submitted_at_label,
        });
    }

    rows.push(
        { label: 'Remaining Balance', value: displayText(payment.remaining_balance_label) },
        { label: 'Balance Status', value: displayText(payment.balance_status_label) },
    );

    if (payment.balance_method_label) {
        rows.push({
            label: 'Balance Paid Via',
            value: payment.balance_method_label,
        });
    }

    if (payment.overall_status_label) {
        rows.push({
            label: 'Overall Payment',
            value: payment.overall_status_label,
        });
    }

    return rows;
}

function latin1Bytes(text: string): Uint8Array {
    const bytes = new Uint8Array(text.length);

    for (let index = 0; index < text.length; index += 1) {
        bytes[index] = text.charCodeAt(index) & 0xff;
    }

    return bytes;
}

function concatBytes(chunks: Uint8Array[]): Uint8Array {
    const total = chunks.reduce((sum, chunk) => sum + chunk.length, 0);
    const merged = new Uint8Array(total);
    let offset = 0;

    for (const chunk of chunks) {
        merged.set(chunk, offset);
        offset += chunk.length;
    }

    return merged;
}

function pdfSafeText(value: string): string {
    return value
        .replaceAll('\u2013', '-')
        .replaceAll('\u2014', '-')
        .replaceAll('\u2018', "'")
        .replaceAll('\u2019', "'")
        .replaceAll('\u201c', '"')
        .replaceAll('\u201d', '"')
        .replaceAll('\u2022', '*')
        .replaceAll('\u00a0', ' ')
        .replaceAll('—', '-')
        .split('')
        .map((character) => {
            const code = character.charCodeAt(0);

            if (code === 10 || code === 13) {
                return ' ';
            }

            if (code < 32) {
                return '';
            }

            if (code > 255) {
                return '?';
            }

            return character;
        })
        .join('');
}

function pdfString(value: string): string {
    let output = '(';

    for (const character of pdfSafeText(value)) {
        const code = character.charCodeAt(0);

        if (character === '\\' || character === '(' || character === ')') {
            output += `\\${character}`;
        } else if (code < 32 || code > 126) {
            output += `\\${code.toString(8).padStart(3, '0')}`;
        } else {
            output += character;
        }
    }

    return `${output})`;
}

function fitText(value: string, maxWidth: number, fontSize: number): string {
    const text = pdfSafeText(value);
    const average = fontSize * 0.5;

    if (text.length * average <= maxWidth) {
        return text;
    }

    const maxChars = Math.max(1, Math.floor((maxWidth - average * 2) / average));

    return `${text.slice(0, maxChars).trimEnd()}...`;
}

function wrapText(value: string, maxWidth: number, fontSize: number): string[] {
    const words = pdfSafeText(value).split(/\s+/).filter(Boolean);
    const lines: string[] = [];
    let current = '';

    words.forEach((word) => {
        const next = current ? `${current} ${word}` : word;

        if (next.length * fontSize * 0.5 > maxWidth && current) {
            lines.push(current);
            current = word;
            return;
        }

        current = next;
    });

    if (current) {
        lines.push(current);
    }

    return lines.length > 0 ? lines : [''];
}

async function loadLogoJpeg(): Promise<LogoImage | null> {
    try {
        const image = new Image();
        image.src = logoUrl();
        await image.decode();

        const size = 160;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const context = canvas.getContext('2d');

        if (!context) {
            return null;
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, size, size);
        context.drawImage(image, 0, 0, size, size);

        const blob = await new Promise<Blob | null>((resolve) => {
            canvas.toBlob(resolve, 'image/jpeg', 0.92);
        });

        if (!blob) {
            return null;
        }

        return {
            bytes: new Uint8Array(await blob.arrayBuffer()),
            width: size,
            height: size,
        };
    } catch {
        return null;
    }
}

function statusFill(status: BoatBookingRecord['status']): [number, number, number] {
    if (status === 'confirmed' || status === 'completed') {
        return [0.875, 0.965, 0.918];
    }
    if (status === 'cancelled' || status === 'rejected' || status === 'passenger_no_show') {
        return [0.992, 0.925, 0.925];
    }

    return [0.992, 0.91, 0.863];
}

function statusInk(status: BoatBookingRecord['status']): [number, number, number] {
    if (status === 'confirmed' || status === 'completed') {
        return [0.122, 0.663, 0.443];
    }
    if (status === 'cancelled' || status === 'rejected' || status === 'passenger_no_show') {
        return [0.886, 0.361, 0.361];
    }

    return [0.753, 0.337, 0.129];
}

function assemblePdf(content: string, logo: LogoImage | null): Uint8Array {
    const pageWidth = 595;
    const pageHeight = 842;

    const objects: Array<string | Uint8Array> = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];

    if (logo) {
        objects.push(
            concatBytes([
                latin1Bytes(
                    `<< /Type /XObject /Subtype /Image /Width ${logo.width} /Height ${logo.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${logo.bytes.length} >>\nstream\n`,
                ),
                logo.bytes,
                latin1Bytes('\nendstream'),
            ]),
        );
    }

    const imageObject = logo ? 5 : null;
    const xObject = imageObject ? ` /XObject << /Im1 ${imageObject} 0 R >>` : '';
    const stream = `${content}\n`;
    const pageNumber = objects.length + 1;
    const contentNumber = objects.length + 2;

    objects.push(
        `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>${xObject} >> /Contents ${contentNumber} 0 R >>`,
    );
    objects.push(`<< /Length ${latin1Bytes(stream).length} >>\nstream\n${stream}endstream`);
    objects[1] = `<< /Type /Pages /Kids [${pageNumber} 0 R] /Count 1 >>`;

    const chunks: Uint8Array[] = [];
    const offsets = [0];
    let position = 0;
    const header = '%PDF-1.4\n';
    chunks.push(latin1Bytes(header));
    position += header.length;

    objects.forEach((body, index) => {
        const object =
            typeof body === 'string'
                ? latin1Bytes(`${index + 1} 0 obj\n${body}\nendobj\n`)
                : concatBytes([
                    latin1Bytes(`${index + 1} 0 obj\n`),
                    body,
                    latin1Bytes('\nendobj\n'),
                ]);
        offsets.push(position);
        chunks.push(object);
        position += object.length;
    });

    const xrefStart = position;
    let xref = `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;

    for (let index = 1; index <= objects.length; index += 1) {
        xref += `${String(offsets[index]).padStart(10, '0')} 00000 n \n`;
    }

    const trailer = `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefStart}\n%%EOF\n`;
    chunks.push(latin1Bytes(xref), latin1Bytes(trailer));

    return concatBytes(chunks);
}

async function buildRentalSlipPdf(booking: BoatBookingRecord): Promise<Uint8Array> {
    const logo = await loadLogoJpeg();
    const pageWidth = 595;
    const margin = 34;
    const cardX = margin;
    const cardW = pageWidth - margin * 2;
    const pad = 22;
    const labelX = cardX + pad;
    const valueMaxW = cardW - pad * 2 - 210;
    const valueX = cardX + cardW - pad;

    const commands: string[] = [];
    let y = 808;

    commands.push('0.843 0.910 0.965 RG');
    commands.push('1 w');
    commands.push(`${cardX} ${margin} ${cardW} ${842 - margin * 2} re S`);

    const headerH = 92;
    const headerY = y - headerH;
    commands.push('0.102 0.353 0.843 rg');
    commands.push(`${cardX + 1} ${headerY} ${cardW - 2} ${headerH} re f`);

    if (logo) {
        commands.push(`q 34 0 0 34 ${labelX} ${headerY + 28} cm /Im1 Do Q`);
    }

    commands.push('1 1 1 rg');
    commands.push(
        `BT /F2 17 Tf 1 0 0 1 ${labelX + 44} ${headerY + 54} Tm ${pdfString('SAFESAKAY')} Tj ET`,
    );
    commands.push(
        `BT /F1 9 Tf 1 0 0 1 ${labelX + 44} ${headerY + 38} Tm ${pdfString('Safer Boats, Safe Trips, SafeSakay')} Tj ET`,
    );

    y = headerY - 20;
    const [sbgR, sbgG, sbgB] = statusFill(booking.status);
    const [sInkR, sInkG, sInkB] = statusInk(booking.status);
    const statusLabel = pdfSafeText(booking.status_label.toUpperCase());
    const badgeW = Math.min(160, statusLabel.length * 5.5 + 24);
    commands.push(`${sbgR} ${sbgG} ${sbgB} rg`);
    commands.push(`${labelX} ${y - 14} ${badgeW} 18 re f`);
    commands.push(`${sInkR} ${sInkG} ${sInkB} rg`);
    commands.push(
        `BT /F2 8 Tf 1 0 0 1 ${labelX + 8} ${y - 10} Tm ${pdfString(statusLabel)} Tj ET`,
    );

    commands.push('0.247 0.290 0.388 rg');
    commands.push(
        `BT /F1 9 Tf 1 0 0 1 ${cardX + cardW - pad - 120} ${y - 8} Tm ${pdfString('Reference No.')} Tj ET`,
    );
    commands.push('0.102 0.353 0.843 rg');
    commands.push(
        `BT /F2 10 Tf 1 0 0 1 ${cardX + cardW - pad - 120} ${y - 22} Tm ${pdfString(`#${booking.code}`)} Tj ET`,
    );

    y -= 44;
    commands.push('0.086 0.227 0.373 rg');
    commands.push(`BT /F2 18 Tf 1 0 0 1 ${labelX} ${y} Tm ${pdfString('Rental Slip')} Tj ET`);
    y -= 16;
    commands.push('0.247 0.290 0.388 rg');
    commands.push(
        `BT /F1 10 Tf 1 0 0 1 ${labelX} ${y} Tm ${pdfString(`Request ID: #${booking.code}`)} Tj ET`,
    );
    y -= 22;

    const drawRow = (label: string, value: string) => {
        commands.push('0.247 0.290 0.388 rg');
        commands.push(`BT /F1 9 Tf 1 0 0 1 ${labelX} ${y} Tm ${pdfString(label)} Tj ET`);
        commands.push('0.086 0.227 0.373 rg');
        const fitted = fitText(value, valueMaxW, 9);
        const textWidth = fitted.length * 4.6;
        commands.push(
            `BT /F2 9 Tf 1 0 0 1 ${valueX - textWidth} ${y} Tm ${pdfString(fitted)} Tj ET`,
        );
        y -= 12;
        commands.push('0.933 0.953 0.980 rg');
        commands.push(`${labelX} ${y + 4} ${cardW - pad * 2} 0.6 re f`);
        y -= 6;
    };

    detailRows(booking).forEach((row) => drawRow(row.label, row.value));

    const payments = paymentRows(booking);
    if (payments.length > 0) {
        y -= 6;
        commands.push('0.102 0.353 0.843 rg');
        commands.push(
            `BT /F2 10 Tf 1 0 0 1 ${labelX} ${y} Tm ${pdfString('PAYMENT')} Tj ET`,
        );
        y -= 18;
        payments.forEach((row) => drawRow(row.label, row.value));
    }

    y -= 8;
    const note =
        'Keep this slip during boarding. Show the payment details and GCash reference to the boat operator if asked.';
    const noteLines = wrapText(note, cardW - pad * 2 - 20, 8);
    const noteBoxH = noteLines.length * 11 + 14;
    commands.push('0.953 0.969 1 rg');
    commands.push(`${labelX} ${y - noteBoxH} ${cardW - pad * 2} ${noteBoxH} re f`);
    commands.push('0.086 0.227 0.373 rg');
    noteLines.forEach((line, index) => {
        commands.push(
            `BT /F1 8 Tf 1 0 0 1 ${labelX + 8} ${y - 12 - index * 11} Tm ${pdfString(line)} Tj ET`,
        );
    });

    y -= noteBoxH + 16;
    commands.push('0.247 0.290 0.388 rg');
    commands.push(
        `BT /F1 8 Tf 1 0 0 1 ${labelX} ${Math.max(margin + 12, y)} Tm ${pdfString(`SafeSakay barangay boat rental - Generated for #${booking.code}`)} Tj ET`,
    );

    return assemblePdf(commands.join('\n'), logo);
}

export async function downloadRentalSlip(booking: BoatBookingRecord): Promise<void> {
    const bytes = await buildRentalSlipPdf(booking);
    downloadPdfBytes(bytes, slipFilename(booking.code));
}
