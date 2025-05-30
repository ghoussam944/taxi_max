<?php

$logFile = __DIR__ . '/users/rec_webhook_log.txt';
// Get raw POST data
$jsonData = file_get_contents('php://input');

// Decode JSON data into an associative array
$data = json_decode($jsonData, true);

// Write to file (append mode)
file_put_contents($logFile, $jsonData, FILE_APPEND | LOCK_EX);

// Check if the decoding was successful
if ($data === null) {
    file_put_contents($logFile, "==== Invalid JSON at " . date('Y-m-d H:i:s') . " ====\n" . $jsonData . "\n\n", FILE_APPEND | LOCK_EX);
    echo "Invalid JSON data.";
    exit;
}

$verify_token = '****************';
$access_token = '****************';
$phone_number_id = '****************';


$code = $data['code'] ?? 500;

$passengerJobId = isset($data['passengerjobid']) ? $data['passengerjobid'] : '';
$passengerSystemId = isset($data['systemid']) ? $data['systemid'] : '';
$passengerName = isset($data['passengername']) ? $data['passengername'] : '';
$passengerTelephone = isset($data['passengertelephone']) ? $data['passengertelephone'] : '';
$passengerPickup = isset($data['passengerpickup']) ? $data['passengerpickup'] : '';
$passengerDropoff = isset($data['passengerdropoff']) ? $data['passengerdropoff'] : '';
$passengerAmount = isset($data['passengeramount']) ? $data['passengeramount'] : '';
$passengerLuggage = isset($data['passengerluggage']) ? $data['passengerluggage'] : '';
$travelDistant = isset($data['traveldistant']) ? $data['traveldistant'] : '';
$greetings = isset($data['greetings']) ? $data['greetings'] : '';
$Message = isset($data['Message']) ? $data['Message'] : '';

$pickupTime = isset($data['bookingtime']) ? $data['bookingtime'] : '';
$specialRequest = isset($data['special']) ? $data['special'] : '';

// Extract vehicle details
$vehicleTypes = $data['vehicletype']['vehicle'] ?? '';

if ($vehicleTypes){
$vehicleDetails = [];
foreach ($vehicleTypes as $vehicle) {
    $vehicleDetails[] = [
        'name' => $vehicle['@name'],
        'price' => $vehicle['@price']
    ];
}
}
// Add a timestamp for logging
$timestamp = date('Y-m-d H:i:s');



$responseMessage = "{$greetings}\n\n";
$responseMessage .= "- 📍 Pickup: {$passengerPickup}\n";
$responseMessage .= "- 🏁 Destination: {$passengerDropoff}\n";
$responseMessage .= "- 📅 Pickup Time: {$pickupTime}\n";  // You can modify this if you want a different pickup time
$responseMessage .= "- 🧍 Passengers: {$passengerAmount}\n";
$responseMessage .= "- 🧳 Luggage: {$passengerLuggage}\n";
$responseMessage .= "- 🛒 Special Requests: {$specialRequest}\n\n";  // You can replace "" if you have special request details
$responseMessage .= "Please select your vehicle type or other options:\n";
$vehicleLetter = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

if ($vehicleTypes){ 
    foreach ($vehicleDetails as $index => $vehicle) {
        $responseMessage .= "🚗 ({$vehicleLetter[$index]}) {$vehicle['name']} (£{$vehicle['price']})\n";
    }
}

$responseMessage .= "❌ (cancel) Cancel Booking\n";
$responseMessage .= "✏ (edit) Edit Booking\n";

if ($code == 200){
    saveBooking($passengerTelephone, $passengerName, $passengerPickup, $passengerDropoff, $pickupTime, $passengerAmount, $passengerLuggage, $specialRequest, $passengerJobId, $passengerSystemId, $responseMessage);
    sendWhatsAppMessage($passengerTelephone, $responseMessage, $access_token, $phone_number_id);
}elseif ($code == 100){

    sendWhatsAppMessage($passengerTelephone, $Message, $access_token, $phone_number_id);


}else{
    //savedBookings($passengerTelephone);
    sendWhatsAppMessage($passengerTelephone, $greetings, $access_token, $phone_number_id);
}


function sendWhatsAppMessage($to, $message, $token, $phone_number_id) {
    $url = "https://graph.facebook.com/v18.0/{$phone_number_id}/messages";
    $data = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $message],
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$token}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $message_id = null;
    $responseData = json_decode($response, true);
    if (isset($responseData['messages'][0]['id'])) {
        $message_id = $responseData['messages'][0]['id'];
    }

    file_put_contents('users/whatsapp_response.txt', 
        "To: $to\nMessage: $message\nHTTP Code: $http_code\nMessage ID: $message_id\nResponse: $response\nCurl Error: $error\n\n", 
        FILE_APPEND
    );

    updateBooking($to, $message_id);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'wamid' => $message_id,
        'message' => 'Booking received successfully'
    ]);
    //return $message_id; // Optionally return the message ID
}

function updateBooking($sender, $message_id) {
    $file = 'users/bookings.json';
    $bookings = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (isset($bookings[$sender])) {
        $bookings[$sender]['wamid'] = $message_id;
        file_put_contents($file, json_encode($bookings, JSON_PRETTY_PRINT));
    }
}


function savedBookings($sender) { 
    $file = __DIR__ . '/users/bookings.json';
    $allBookings = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    $now = date('Y-m-d H:i:s');

    $bookingEntry = [
        'sender' => $sender,
        'code' => 'Error',
        'date' => $now
    ];

    $allBookings[] = $bookingEntry;

    file_put_contents($file, json_encode($allBookings, JSON_PRETTY_PRINT));
}

function saveBooking($sender, $name, $passengerPickup, $passengerDropoff, $pickupTime, $passengerAmount, $passengerLuggage, $specialRequest, $passengerJobId, $passengerSystemId, $responseMessage) {
    $file = 'users/bookings.json';
    $bookings = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    $bookings[$sender] = [
        'name' => $name,
        'pickup' => $passengerPickup,
        'destination' => $passengerDropoff,
        'time' => $pickupTime,
        'passengers' => $passengerAmount,
        'luggage' => $passengerLuggage,
        'special' => $specialRequest,
        'jobid' => $passengerJobId,
        'systemid' => $passengerSystemId,
        'message' => $responseMessage,
        'driverid' => 0,
        'last_updated' => date('Y-m-d H:i:s'),
        'status' => 0
    ];

    file_put_contents($file, json_encode($bookings, JSON_PRETTY_PRINT));
}


http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'message' => 'Booking received successfully'
]);

?>