<?php
require_once "config.php";

$sender = $_POST['sender'] ?? $_GET['sender'] ?? 'DEMO-GSM';
$message = trim($_POST['message'] ?? $_GET['message'] ?? '');

if (!$message && isset($_GET['demo'])) {
    $message = 'SOS Maria Santos | Purok 1 | MEDICAL: insulin';
}

if (!$message) {
    http_response_code(400);
    exit("Missing message. Example: SOS Maria Santos | Purok 1 | MEDICAL: insulin");
}

$parts = array_map('trim', explode('|', $message));
$first = $parts[0] ?? '';
$reportType = 'SOS';
$name = preg_replace('/^SOS\s*/i','',$first);
$location = $parts[1] ?? 'Unknown';
$need = $parts[2] ?? 'General assistance';

if ($conn) {
    $stmt = $conn->prepare("INSERT INTO sms_reports(sender_number,report_type,citizen_name,location_text,need_text,raw_message) VALUES(?,?,?,?,?,?)");
    $stmt->bind_param("ssssss",$sender,$reportType,$name,$location,$need,$message);
    $stmt->execute();
    $id = $stmt->insert_id;
    header("Location: sms.php?logged=".$id);
    exit;
}

header("Content-Type: application/json");
echo json_encode([
    "success"=>true,
    "demo"=>true,
    "message"=>"Database is offline; payload parsed successfully.",
    "parsed"=>["sender"=>$sender,"type"=>$reportType,"name"=>$name,"location"=>$location,"need"=>$need]
]);
?>