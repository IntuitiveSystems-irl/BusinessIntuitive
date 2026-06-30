<?php
require_once __DIR__ . '/config.php';
/**
 * Business Intuitive — "Free Systems Read" personalized PDF.
 *
 * Pure-PHP (FPDF) generator. The production FPM runtime only has
 * curl/json/zlib/iconv (no mbstring/gd/dom), so we embed the brand fonts
 * (Inter + Fraunces) as pre-generated FPDF font definitions and convert
 * UTF-8 input to cp1252 via iconv.
 *
 * Brand palette (matches businessintuitive.tech + socials):
 *   black #0B0C0E · blue #2563EB / #3B82F6 · teal #16A2AE · lime #C8FF5A
 *
 * Entry point: build_systems_read_pdf($name, $firm, $challenge): string  (raw PDF bytes)
 */

require_once __DIR__ . '/fpdf/fpdf.php';

/* ---- brand colors (RGB) ---- */
const SR_BG        = [11, 12, 14];
const SR_SURFACE   = [20, 21, 24];
const SR_BLUE      = [37, 99, 235];
const SR_BLUE_BRT  = [59, 130, 246];
const SR_TEAL      = [22, 162, 174];
const SR_LIME      = [200, 255, 90];
const SR_TEXT      = [236, 239, 241];
const SR_SOFT      = [167, 180, 184];
const SR_MUTE      = [110, 119, 123];
const SR_LINE      = [38, 40, 44];

class SystemsReadPDF extends FPDF
{
    public $firstName = 'there';
    public $firmName  = 'your business';

    public function setData($first, $firm)
    {
        $this->firstName = $first;
        $this->firmName  = $firm;
    }

    /* ===== brand color setters ===== */
    private function tcol($c) { $this->SetTextColor($c[0], $c[1], $c[2]); }
    private function fcol($c) { $this->SetFillColor($c[0], $c[1], $c[2]); }
    private function dcol($c) { $this->SetDrawColor($c[0], $c[1], $c[2]); }

    /* ===== page chrome ===== */
    public function Header()
    {
        // full-bleed dark background
        $this->fcol(SR_BG);
        $this->Rect(0, 0, $this->w, $this->h, 'F');

        // top accent bar: blue full width + lime kicker segment
        $this->fcol(SR_BLUE);
        $this->Rect(0, 0, $this->w, 1.6, 'F');
        $this->fcol(SR_LIME);
        $this->Rect(0, 0, 32, 1.6, 'F');

        // brand mark + wordmark
        $this->drawMark(18, 11, 12);
        $this->SetXY(34, 12.4);
        $this->SetFont('Inter', 'B', 11);
        $this->tcol(SR_TEXT);
        $this->Cell(80, 6, sr_tx('Business Intuitive'), 0, 0, 'L');

        // right: eyebrow
        $this->SetFont('Inter', '', 8);
        $this->tcol(SR_TEAL);
        $this->SetXY($this->w - 92, 13.2);
        $this->Cell(74, 5, sr_tx('FREE SYSTEMS READ'), 0, 0, 'R');

        // start body lower
        $this->SetY(40);
    }

    public function Footer()
    {
        $this->SetY(-16);
        $this->dcol(SR_LINE);
        $this->SetLineWidth(0.2);
        $this->Line(18, $this->GetY(), $this->w - 18, $this->GetY());
        $this->SetY(-13);
        $this->SetFont('Inter', '', 8);
        $this->tcol(SR_MUTE);
        $this->Cell(0, 5, sr_tx('businessintuitive.tech'), 0, 0, 'L');
        $this->tcol(SR_MUTE);
        $this->Cell(0, 5, sr_tx('The Intelligence Layer for Founders'), 0, 0, 'R');
    }

    /* ===== simplified brand mark (rounded tile + ascending line + lime signal) ===== */
    public function drawMark($x, $y, $s)
    {
        // tile
        $this->fcol(SR_SURFACE);
        $this->dcol(SR_BLUE);
        $this->SetLineWidth(0.25);
        $this->RoundedRect($x, $y, $s, $s, 2.6, 'DF');

        // ascending line (blue -> teal), in tile coords
        $this->SetLineWidth(0.7);
        $px = $x + $s * 0.18; $py = $y + $s * 0.74;
        $p2x = $x + $s * 0.44; $p2y = $y + $s * 0.56;
        $p3x = $x + $s * 0.62; $p3y = $y + $s * 0.62;
        $p4x = $x + $s * 0.80; $p4y = $y + $s * 0.26;
        $this->dcol(SR_BLUE);    $this->Line($px, $py, $p2x, $p2y);
        $this->dcol(SR_BLUE_BRT); $this->Line($p2x, $p2y, $p3x, $p3y);
        $this->dcol(SR_TEAL);    $this->Line($p3x, $p3y, $p4x, $p4y);

        // lime signal dot
        $this->fcol(SR_LIME);
        $this->Circle($p4x, $p4y, $s * 0.10, 'F');
    }

    /* ===== shape helpers (standard FPDF snippets) ===== */
    public function RoundedRect($x, $y, $w, $h, $r, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        if ($style == 'F') $op = 'f';
        elseif ($style == 'FD' || $style == 'DF') $op = 'B';
        else $op = 'S';
        $MyArc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_Arc($xc + $r * $MyArc, $yc - $r, $xc + $r, $yc - $r * $MyArc, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc + $r, $yc + $r * $MyArc, $xc + $r * $MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_Arc($xc - $r * $MyArc, $yc + $r, $xc - $r, $yc + $r * $MyArc, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_Arc($xc - $r, $yc - $r * $MyArc, $xc - $r * $MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    public function _Arc($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $k = $this->k;
        $hp = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $k, ($hp - $y1) * $k,
            $x2 * $k, ($hp - $y2) * $k,
            $x3 * $k, ($hp - $y3) * $k));
    }

    public function Circle($x, $y, $r, $style = '')
    {
        $this->Ellipse($x, $y, $r, $r, $style);
    }

    public function Ellipse($x, $y, $rx, $ry, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        if ($style == 'F') $op = 'f';
        elseif ($style == 'FD' || $style == 'DF') $op = 'B';
        else $op = 'S';
        $lx = 4 / 3 * (sqrt(2) - 1) * $rx;
        $ly = 4 / 3 * (sqrt(2) - 1) * $ry;
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x + $rx) * $k, ($hp - $y) * $k,
            ($x + $rx) * $k, ($hp - ($y - $ly)) * $k,
            ($x + $lx) * $k, ($hp - ($y - $ry)) * $k,
            $x * $k, ($hp - ($y - $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $lx) * $k, ($hp - ($y - $ry)) * $k,
            ($x - $rx) * $k, ($hp - ($y - $ly)) * $k,
            ($x - $rx) * $k, ($hp - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $rx) * $k, ($hp - ($y + $ly)) * $k,
            ($x - $lx) * $k, ($hp - ($y + $ry)) * $k,
            $x * $k, ($hp - ($y + $ry)) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c %s',
            ($x + $lx) * $k, ($hp - ($y + $ry)) * $k,
            ($x + $rx) * $k, ($hp - ($y + $ly)) * $k,
            ($x + $rx) * $k, ($hp - $y) * $k, $op));
    }

    /* ===== content helpers ===== */
    public function eyebrow($text, $color = SR_TEAL)
    {
        $this->SetFont('Inter', 'B', 8.5);
        $this->tcol($color);
        $this->Cell(0, 5, sr_tx(strtoupper($text)), 0, 1, 'L');
        $this->Ln(1.5);
    }
}

/* ============================================================
 * Helpers
 * ============================================================ */

function sr_tx($s)
{
    $s = (string) $s;
    $s = preg_replace('/\s+/u', ' ', trim($s));
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    if ($out === false || $out === null) {
        $out = preg_replace('/[^\x20-\x7E]/', '', $s);
    }
    return $out;
}

/**
 * Pick up to 3 "where we'd look first" pillars from the customer's challenge text.
 * Keyword-matched (no LLM) for a personalized-but-reliable read.
 */
function sr_pick_pillars($challenge)
{
    $catalog = [
        'quoting'    => ['Quote-to-cash automation', "Turn estimates and proposals into a few clicks - consistent pricing, instant send, nothing stuck in a draft."],
        'leads'      => ['Lead capture & follow-up', "Every inbound routed, logged, and followed up automatically - so no lead goes cold in an inbox."],
        'data'       => ['One source of truth', "Live dashboards replace the spreadsheet shuffle - your numbers in one place, updated in real time."],
        'scheduling' => ['Scheduling & dispatch', "Jobs, crews, and calendars coordinated in one view instead of phone tag and double-bookings."],
        'billing'    => ['Billing & payments', "Invoicing and payments wired into the workflow, so cash stops slipping through the cracks."],
        'admin'      => ['Admin & handoffs', "Kill the manual re-entry between tools - the system carries the work across automatically."],
        'portal'     => ['Client portal', "A branded space for clients to see status, documents, and payments - cutting your email volume."],
    ];
    $rules = [
        'quoting'    => ['quote', 'quoting', 'bid', 'proposal', 'estimate', 'pricing'],
        'leads'      => ['lead', 'leads', 'follow', 'crm', 'pipeline', 'inbound', 'contact'],
        'data'       => ['spreadsheet', 'excel', 'sheet', 'number', 'report', 'data', 'dashboard', 'track', 'visib'],
        'scheduling' => ['schedul', 'dispatch', 'calendar', 'booking', 'jobs', 'crew', 'route', 'appointment'],
        'billing'    => ['invoice', 'payment', 'billing', 'collect', 'cash', 'paid'],
        'admin'      => ['manual', 'admin', 'paperwork', 'double', 're-enter', 'reenter', 'copy', 'paste', 'duplicate'],
        'portal'     => ['client', 'portal', 'customer', 'document', 'docs', 'update'],
    ];
    $text = strtolower((string) $challenge);
    $picked = [];
    foreach ($rules as $key => $words) {
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) { $picked[$key] = true; break; }
        }
    }
    $order = array_keys($picked);
    foreach (['data', 'admin', 'portal'] as $fallback) {
        if (count($order) >= 3) break;
        if (!in_array($fallback, $order, true)) $order[] = $fallback;
    }
    $out = [];
    foreach (array_slice($order, 0, 3) as $key) $out[] = $catalog[$key];
    return $out;
}

/**
 * Build the personalized PDF. Returns raw PDF bytes.
 */
function build_systems_read_pdf($name, $firm, $challenge)
{
    $first = trim((string) $name);
    $first = $first !== '' ? preg_split('/\s+/', $first)[0] : 'there';
    $firm  = trim((string) $firm) !== '' ? trim((string) $firm) : 'your business';
    $challenge = trim((string) $challenge);
    if (strlen($challenge) > 680) $challenge = substr($challenge, 0, 677) . '...';

    $pdf = new SystemsReadPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetMargins(18, 16, 18);
    $pdf->AddFont('Inter', '', 'Inter-Regular.json');
    $pdf->AddFont('Inter', 'B', 'Inter-SemiBold.json');
    $pdf->AddFont('Fraunces', '', 'Fraunces-SemiBold.json');
    $pdf->setData($first, $firm);
    $pdf->AddPage();

    $L = 18;
    $W = $pdf->GetPageWidth() - 36;

    // eyebrow
    $pdf->SetX($L);
    $pdf->eyebrow('Prepared for ' . $first . ' - ' . date('F j, Y'));

    // headline (Fraunces, white)
    $pdf->SetX($L);
    $pdf->SetFont('Fraunces', '', 26);
    $pdf->SetTextColor(236, 239, 241);
    $headline = ($firm === 'your business')
        ? "Here's where we'd start."
        : $firm . ", here's where we'd start.";
    $pdf->MultiCell($W, 10.5, sr_tx($headline), 0, 'L');

    // blue accent rule
    $pdf->Ln(1.5);
    $pdf->SetDrawColor(37, 99, 235);
    $pdf->SetLineWidth(0.9);
    $pdf->Line($L, $pdf->GetY(), $L + 26, $pdf->GetY());
    $pdf->Ln(5);

    // intro paragraph
    $pdf->SetX($L);
    $pdf->SetFont('Inter', '', 10.5);
    $pdf->SetTextColor(167, 180, 184);
    $intro = "Thanks, $first. You told us what's slowing $firm down, so here's a quick first read from the "
        . "Business Intuitive team - the lens we'll use when we dig in. This isn't your full report yet; "
        . "Lindsay reviews every one personally and sends the complete systems read within one business day.";
    $pdf->MultiCell($W, 5.6, sr_tx($intro), 0, 'L');
    $pdf->Ln(6);

    // "what you told us" card
    $pdf->SetX($L);
    $pdf->eyebrow('What you told us');
    $cardY = $pdf->GetY();
    $quote = $challenge !== ''
        ? '"' . $challenge . '"'
        : "You asked for a systems read - we'll start with a short call to map the single biggest bottleneck.";
    // measure card height via a dry MultiCell
    $pdf->SetFont('Inter', '', 10);
    $lines = sr_count_lines($pdf, $W - 16, 5.4, sr_tx($quote));
    $cardH = $lines * 5.4 + 9;
    $pdf->SetFillColor(20, 21, 24);
    $pdf->RoundedRect($L, $cardY, $W, $cardH, 3, 'F');
    // blue left accent bar
    $pdf->SetFillColor(37, 99, 235);
    $pdf->RoundedRect($L, $cardY, 1.8, $cardH, 0.6, 'F');
    $pdf->SetXY($L + 9, $cardY + 4.5);
    $pdf->SetFont('Inter', '', 10);
    $pdf->SetTextColor(216, 222, 224);
    $pdf->MultiCell($W - 16, 5.4, sr_tx($quote), 0, 'L');
    $pdf->SetY($cardY + $cardH + 8);

    // "where we'd look first"
    $pdf->SetX($L);
    $pdf->eyebrow("Where we'd look first");
    $pillars = sr_pick_pillars($challenge);
    $numColors = [[37, 99, 235], [22, 162, 174], [59, 130, 246]];
    $i = 0;
    foreach ($pillars as $p) {
        $rowY = $pdf->GetY();
        // number
        $pdf->SetFont('Fraunces', '', 20);
        $c = $numColors[$i % 3];
        $pdf->SetTextColor($c[0], $c[1], $c[2]);
        $pdf->SetXY($L, $rowY);
        $pdf->Cell(14, 8, sprintf('%02d', $i + 1), 0, 0, 'L');
        // title
        $pdf->SetXY($L + 15, $rowY - 0.5);
        $pdf->SetFont('Inter', 'B', 11);
        $pdf->SetTextColor(236, 239, 241);
        $pdf->Cell($W - 15, 6, sr_tx($p[0]), 0, 2, 'L');
        // desc
        $pdf->SetX($L + 15);
        $pdf->SetFont('Inter', '', 9.5);
        $pdf->SetTextColor(167, 180, 184);
        $pdf->MultiCell($W - 15, 5, sr_tx($p[1]), 0, 'L');
        $pdf->Ln(3.5);
        $i++;
    }

    $pdf->Ln(1);
    // divider
    $pdf->SetDrawColor(38, 40, 44);
    $pdf->SetLineWidth(0.2);
    $pdf->Line($L, $pdf->GetY(), $L + $W, $pdf->GetY());
    $pdf->Ln(6);

    // what happens next
    $pdf->SetX($L);
    $pdf->eyebrow('What happens next', [59, 130, 246]);
    $pdf->SetX($L);
    $pdf->SetFont('Inter', '', 10.5);
    $pdf->SetTextColor(167, 180, 184);
    $pdf->MultiCell($W, 5.6, sr_tx("Your full, tailored systems read lands in your inbox within one business day. Want to move faster? Request a 15-minute call below - no pitch, no template traps."), 0, 'L');
    $pdf->Ln(4);

    // CTA pill (lime) + link
    $pillW = 78; $pillH = 11; $px = $L; $py = $pdf->GetY();
    $pdf->SetFillColor(200, 255, 90);
    $pdf->RoundedRect($px, $py, $pillW, $pillH, 5.5, 'F');
    $pdf->SetXY($px, $py);
    $pdf->SetFont('Inter', 'B', 10.5);
    $pdf->SetTextColor(11, 12, 14);
    $pdf->Cell($pillW, $pillH, sr_tx('Book a 15-min systems call'), 0, 0, 'C');
    $pdf->Link($px, $py, $pillW, $pillH, 'https://businessintuitive.tech/#book');
    // url to the right
    $pdf->SetXY($px + $pillW + 6, $py);
    $pdf->SetFont('Inter', '', 9.5);
    $pdf->SetTextColor(22, 162, 174);
    $pdf->Cell(0, $pillH, sr_tx('businessintuitive.tech/#book'), 0, 0, 'L');

    return $pdf->Output('S');
}

/** Count how many lines a MultiCell will wrap to (for card sizing). */
function sr_count_lines($pdf, $w, $h, $txt)
{
    $cw = 0;
    $lines = 1;
    $space = $pdf->GetStringWidth(' ');
    $words = explode(' ', $txt);
    foreach ($words as $word) {
        $ww = $pdf->GetStringWidth($word);
        if ($cw > 0 && $cw + $space + $ww > $w) {
            $lines++;
            $cw = $ww;
        } else {
            $cw += ($cw > 0 ? $space : 0) + $ww;
        }
    }
    return max(1, $lines);
}
