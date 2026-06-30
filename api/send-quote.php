<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Resend API Configuration
define('FROM_EMAIL', 'quotes@businessintuitive.tech');
define('TO_EMAIL', 'lbbusiness2025@gmail.com');

// Get POST data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit;
}

// Validate required fields
if (empty($data['fullName']) || empty($data['email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name and email are required']);
    exit;
}

// Service type labels
$serviceLabels = [
    'diagnostic' => 'Strategic Systems Diagnostic',
    'web-app' => 'Custom Web App',
    'automation' => 'Automation Systems',
    'other' => 'Other'
];

$serviceName = $serviceLabels[$data['service']] ?? $data['service'];

// Build email content
$emailContent = buildEmailHTML($data, $serviceName);

// Send email via Resend
$result = sendEmailViaResend(
    TO_EMAIL,
    "New Quote Request - {$serviceName}",
    $emailContent
);

if ($result['success']) {
    // Send confirmation email to customer
    $confirmationContent = buildConfirmationEmail($data, $serviceName);
    sendEmailViaResend(
        $data['email'],
        "Quote Request Received - Business Intuitive",
        $confirmationContent
    );
    
    echo json_encode(['success' => true, 'message' => 'Quote request sent successfully']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $result['message']]);
}

function sendEmailViaResend($to, $subject, $htmlContent) {
    $data = [
        'from' => FROM_EMAIL,
        'to' => [$to],
        'subject' => $subject,
        'html' => $htmlContent
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . RESEND_API_KEY,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return ['success' => true];
    } else {
        $error = json_decode($response, true);
        return [
            'success' => false,
            'message' => $error['message'] ?? 'Failed to send email'
        ];
    }
}

function buildEmailHTML($data, $serviceName) {
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; line-height: 1.6; color: #e8e8e8; background: #080808; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #111; color: #e8e8e8; padding: 30px; text-align: center; border-radius: 16px 16px 0 0; border: 1px solid #222; }
            .header h1 { color: #00d4aa; }
            .content { background: #0a0a0a; padding: 30px; border: 1px solid #222; border-top: none; }
            .field { margin-bottom: 20px; }
            .label { font-weight: 600; color: #999; margin-bottom: 5px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.1em; }
            .value { color: #e8e8e8; }
            .footer { background: #111; color: #666; padding: 20px; text-align: center; border-radius: 0 0 16px 16px; font-size: 14px; border: 1px solid #222; border-top: none; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>New Quote Request</h1>
                <p style="color: #666;">Business Intuitive — Geometric Landing</p>
            </div>
            <div class="content">
                <div class="field">
                    <div class="label">Service Requested:</div>
                    <div class="value">' . htmlspecialchars($serviceName) . '</div>
                </div>
                
                <div class="field">
                    <div class="label">Project Description:</div>
                    <div class="value">' . nl2br(htmlspecialchars($data['projectDescription'] ?? 'Not provided')) . '</div>
                </div>
                
                <div class="field">
                    <div class="label">Timeline:</div>
                    <div class="value">' . htmlspecialchars($data['timeline'] ?? 'Not specified') . '</div>
                </div>
                
                <div class="field">
                    <div class="label">Budget Range:</div>
                    <div class="value">' . htmlspecialchars($data['budget'] ?? 'Not specified') . '</div>
                </div>
                
                <hr style="border: none; border-top: 1px solid #222; margin: 30px 0;">
                
                <h3 style="color: #00d4aa; margin-bottom: 20px;">Contact Information</h3>
                
                <div class="field">
                    <div class="label">Name:</div>
                    <div class="value">' . htmlspecialchars($data['fullName']) . '</div>
                </div>
                
                <div class="field">
                    <div class="label">Email:</div>
                    <div class="value"><a href="mailto:' . htmlspecialchars($data['email']) . '" style="color: #00d4aa;">' . htmlspecialchars($data['email']) . '</a></div>
                </div>';
    
    if (!empty($data['phone'])) {
        $html .= '
                <div class="field">
                    <div class="label">Phone:</div>
                    <div class="value"><a href="tel:' . htmlspecialchars($data['phone']) . '" style="color: #00d4aa;">' . htmlspecialchars($data['phone']) . '</a></div>
                </div>';
    }
    
    if (!empty($data['company'])) {
        $html .= '
                <div class="field">
                    <div class="label">Company:</div>
                    <div class="value">' . htmlspecialchars($data['company']) . '</div>
                </div>';
    }
    
    $html .= '
            </div>
            <div class="footer">
                <p>Business Intuitive</p>
                <p>Received: ' . date('F j, Y \a\t g:i A') . '</p>
            </div>
        </div>
    </body>
    </html>';
    
    return $html;
}

function buildConfirmationEmail($data, $serviceName) {
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, Arial, sans-serif; line-height: 1.6; color: #e8e8e8; background: #080808; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #111; color: #e8e8e8; padding: 40px; text-align: center; border-radius: 16px 16px 0 0; border: 1px solid #222; }
            .header h1 { color: #00d4aa; }
            .content { background: #0a0a0a; padding: 40px; border: 1px solid #222; border-top: none; }
            .footer { background: #111; padding: 20px; text-align: center; border-radius: 0 0 16px 16px; font-size: 14px; color: #666; border: 1px solid #222; border-top: none; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Thank You</h1>
                <p style="color: #666;">We\'ve received your request</p>
            </div>
            <div class="content" style="color: #f0f0f0;">
                <p style="color: #f0f0f0;">Hi ' . htmlspecialchars($data['fullName']) . ',</p>
                
                <p style="color: #f0f0f0;">Thank you for your interest in our <strong style="color: #00d4aa;">' . htmlspecialchars($serviceName) . '</strong> services.</p>
                
                <p style="color: #f0f0f0;">We\'ve received your request and our team will review it carefully. You can expect to hear back within 24 hours with a personalized response and next steps.</p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="sms:+17602100977?body=Hi%20I%27m%20looking%20to%20book%20a%20demo." style="display: inline-block; background: #00d4aa; color: #080808; padding: 14px 28px; text-decoration: none; border-radius: 0; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; font-size: 12px;">Schedule a Call</a>
                </div>
                
                <p style="color: #f0f0f0;"><strong style="color: #00d4aa;">What happens next?</strong></p>
                <ol style="color: #d8d8d8;">
                    <li style="margin-bottom: 8px; color: #d8d8d8;">Our team reviews your project details</li>
                    <li style="margin-bottom: 8px; color: #d8d8d8;">We prepare a customized quote</li>
                    <li style="margin-bottom: 8px; color: #d8d8d8;">You receive a detailed proposal via email</li>
                    <li style="margin-bottom: 8px; color: #d8d8d8;">We schedule a call to discuss your project</li>
                </ol>
                
                <p style="color: #d8d8d8;">If you have any questions, don\'t hesitate to reply to this email.</p>
                
                <p style="color: #f0f0f0;">Best regards,<br>
                <strong style="color: #f0f0f0;">Lindsay Bachman</strong><br>
                <span style="color: #b0b0b0;">Business Intuitive</span></p>
            </div>
            <div class="footer">
                <p>Business Intuitive</p>
                <p>businessintuitive.tech</p>
            </div>
        </div>
    </body>
    </html>';
    
    return $html;
}
?>
