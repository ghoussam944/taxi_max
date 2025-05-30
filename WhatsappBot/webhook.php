<?php

require '../xmpp_lib/vendor/autoload.php';

use Fabiang\Xmpp\Options;
use Fabiang\Xmpp\Client;
use Fabiang\Xmpp\Protocol\Message;

$verify_token = '*************'; // Your verify token
$access_token = '*************'; // Your WhatsApp Cloud API token
$phone_number_id = '*************'; // Found in your WhatsApp Cloud API settings 674464132410163 -- 591876517344686
$openai_key = '*************';

// Handle incoming message
$input = json_decode(file_get_contents('php://input'), true);

$logFile = __DIR__ . '/users/webhook_whatsapp_log.txt';

// Create the "users" directory if it doesn't exist
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0777, true);
}

// Log the input
file_put_contents($logFile, print_r($input, true), FILE_APPEND);

$messageId = $input['entry'][0]['changes'][0]['value']['messages'][0]['id'] ?? '';

if ($messageId && isDuplicateMessage($messageId)) {
    error_log("Duplicate message skipped: $messageId");
    exit;
}

function isDuplicateMessage($messageId) {
    $file = __DIR__ . '/users/processed_msgs.json';
    // Load existing seen message IDs
    $seen = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    // Already processed?
    if (isset($seen[$messageId])) {
        return true;
    }

    // Store new message ID with timestamp
    $seen[$messageId] = time();

    // Optional: clean up old entries (older than 1 day)
    $seen = array_filter($seen, function ($timestamp) {
        return $timestamp > (time() - 86400); // 24 hours
    });

    // Save updated list
    file_put_contents($file, json_encode($seen));

    return false;
}

$contextId = $input['entry'][0]['changes'][0]['value']['messages'][0]['context']['id'] ?? '';

$messageType = $input['entry'][0]['changes'][0]['value']['messages'][0]['type'] ?? '';
$messageData = $input['entry'][0]['changes'][0]['value']['messages'][0] ?? [];

$sender = $messageData['from'] ?? null;
$senderName = $input['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? 'Passenger';

if ($messageType === 'text') {
    $message = $messageData['text']['body'] ?? null;
} elseif ($messageType === 'audio') {
    $mediaId = $messageData['audio']['id'] ?? null;
    if ($mediaId) { 
        $message = transcribeWhatsAppAudio($sender, $mediaId, $access_token, $phone_number_id, $openai_key); 
    }
}elseif ($messageType === 'location'){
    $message = null; 
    $lat = $messageData['location']['latitude'];
    $lng = $messageData['location']['longitude'];

    $filePath = __DIR__ . '/users/location.json';
    // Read existing data or initialize new array
    $existingData = file_exists($filePath) ? json_decode(file_get_contents($filePath), true) : [];
    // Update common fields (new or existing)
    $existingData[$sender]['phone'] = $sender;
    $existingData[$sender]['latitude'] = $lat;
    $existingData[$sender]['longitude'] = $lng;
    $existingData[$sender]['timestamp'] = date('Y-m-d H:i:s');
    // Save back to file
    file_put_contents($filePath, json_encode($existingData, JSON_PRETTY_PRINT));
    $botReply = "Thank you for sharing your location! You can now:\n- Send 1 to make a booking\n- Send 2 to find a local taxi company";

    sendWhatsAppMessage($sender, $botReply, $access_token, $phone_number_id); 
} else {
    $message = null; // Unsupported type
    sendWhatsAppMessage($sender, "The media or text you sent is not supported. Please try again with a valid format.", $access_token, $phone_number_id);
}


if ($message && $sender) {
    saveUserMessage($sender, $message);

    $botReply = getBotReply($message, $sender, $senderName, $contextId);

    if ($botReply == "videohow388"){
        sendWhatsAppVideoFromFile($sender, $botReply, $access_token, $phone_number_id);
    }else{
        sendWhatsAppMessage($sender, $botReply, $access_token, $phone_number_id);
    }
}

function getBotReply($userMessage, $sender, $senderNameFromWhatsApp, $contextId) {
    global $openai_key;
    $lats = '';
    $lngs = '';
    
    $previousLocation = getLocation($sender) ?? '';
    $previousBooking = getBooking($sender) ?? '';
    $progress = $previousBooking['progress'] ?? '';
    $places = getUserPlaces($sender) ?? '';
    $senderName = $previousLocation['name'] ?? $senderNameFromWhatsApp;
    //$token = $previousBooking['systemid'] ?? str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $token = !empty($previousBooking['systemid']) ? $previousBooking['systemid'] : str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $wamid = !empty($previousBooking['wamid']) ? $previousBooking['wamid'] : '';

    $contextId = $contextId ?? 'N/A';


    if ($previousLocation){
        $lats = $previousLocation['latitude'] ?? '';
        $lngs = $previousLocation['longitude'] ?? '';
        $jobid = $previousLocation['jobid'] ?? '';
        $driverid = $previousLocation['driverid'] ?? '';
        $timestamp = $previousLocation['timestamp'] ?? '';

        if ($lats && $lngs){
            $address = getAddressFromCoordinates($lats, $lngs);
        }
    }

    if (strtolower($userMessage) === 'how') {
        return "videohow388";
    }

    if (!$lats || !$lngs){
        return "Hello ".$senderName.", It looks like you haven't shared your location yet. Please share your current location so we can assist you more effectively with your taxi booking. If you're not sure how, just type: How.";
    }

    if ($userMessage === '1') {
        return $senderName." your current address from your shared location is: ".$address."\n\nPlease send us the pick up and drop-off address and make it as clear as possible. \n\n*Note:* You can save your common pick-up points as aliases by typing this as an example: ".$address.". You can use the word 'home' for future bookings.";
    }

    if ($userMessage === '2') {
        $taxiResults = findNearbyTaxis($lats, $lngs);
            if (isset($taxiResults['error'])) {
                $taxiReply = "Sorry, we couldn't find any nearby taxi stands.";
            } else {
                $taxiReply = "Based on your current address: ".$address."\n\n*Here are some nearby taxi services:*\n";
                foreach ($taxiResults as $index => $taxi) {
                    $taxiReply .=   "\n" . ($index + 1) . ". " . $taxi['name'] . 
                                    "\n📍 Address: " . $taxi['address'] . 
                                    "\n📞 Phone: " . $taxi['phone'] . 
                                    "\n⭐ Rating: " . $taxi['rating'] . "\n";
                }
            }

            return $taxiReply;
    }

    if (preg_match('/^(my name is|call me)\s+(.+)/i', $userMessage, $matches)) {
        $newName = trim($matches[2]);
        saveUser($sender, $newName);
        return "Got it! I’ll call you {$newName} from now on.";
    }

    if ($places) {
        foreach ($places as $label => $addresses) {
            $userMessage = preg_replace('/\b' . preg_quote($label, '/') . '\b/i', $addresses, $userMessage);
        }
    }

    if (preg_match('/^(\w+)\s*=\s*(.+)$/i', $userMessage, $matches)) {
        $label = strtolower(trim($matches[1]));
        $address_ = trim($matches[2]);
        saveUserPlace($sender, $label, $address_);
        return ucfirst($label) . " location saved as: {$address_}";
    }

    if ($previousBooking){
        $savedBooking = $previousBooking['message'] ?? '';

        if (strtolower($userMessage) === 'a') {
            if (preg_match('/\(a\)\s+ANY CAR\s+\(£([\d,.]+)\)/i', $savedBooking, $matches)) {
                $recommendedPrice = $matches[1];
                saveConfirmedBooking($sender, $previousBooking, 'a', $wamid, $contextId, $lats, $lngs, $token);
                return "Great choice! ANY CAR ( £$recommendedPrice ), Your taxi will be with you right on time.";
            }
        }

        if (strtolower($userMessage) === 'b') {
            if (preg_match('/\(b\)\s+SALOON\s+\(£([\d,.]+)\)/i', $savedBooking, $matches)) {
                $recommendedPrice = $matches[1];
                saveConfirmedBooking($sender, $previousBooking, 'b', $wamid, $contextId, $lats, $lngs, $token);
                return "Great choice! SALOON ( £$recommendedPrice ), Your taxi will be with you right on time.";
            }
        }

        if (strtolower($userMessage) === 'c') {
            if (preg_match('/\(c\)\s+WHEELCHAIR\s+\(£([\d,.]+)\)/i', $savedBooking, $matches)) {
                $recommendedPrice = $matches[1];
                saveConfirmedBooking($sender, $previousBooking, 'c', $wamid, $contextId, $lats, $lngs, $token);
                return "WHEELCHAIR Vehicle ( £$recommendedPrice ), Your taxi will be with you right on time.";
            }
        }

        if (strtolower($userMessage) === 'd') {
            if (preg_match('/\(d\)\s+ESTATE\s+\(£([\d,.]+)\)/i', $savedBooking, $matches)) {
                $recommendedPrice = $matches[1];
                saveConfirmedBooking($sender, $previousBooking, 'd', $wamid, $contextId, $lats, $lngs, $token);
                return "Great choice! ESTATE vehicle ( £$recommendedPrice ), Your taxi will be with you right on time.";
            }
        }

        if (strtolower($userMessage) === 'e') {
            if (preg_match('/\(e\)\s+6 SEATER\s+\(£([\d,.]+)\)/i', $savedBooking, $matches)) {
                $recommendedPrice = $matches[1];
                saveConfirmedBooking($sender, $previousBooking, 'e', $wamid, $contextId, $lats, $lngs, $token);
                return "Great choice! 6 SEATER Vehicle ( £$recommendedPrice ), Your taxi will be with you right on time.";
            }
        }

        if (strtolower($userMessage) === 'f') {
            if (preg_match('/\(f\)\s+8 SEATER\s+\(£([\d,.]+)\)/i', $savedBooking, $matches)) {
                $recommendedPrice = $matches[1];
                saveConfirmedBooking($sender, $previousBooking, 'f', $wamid, $contextId, $lats, $lngs, $token);
                return "Great choice! 8 SEATER vehicle ( £$recommendedPrice ), Your taxi will be with you right on time.";
            }
        }

        if (strtolower($userMessage) === 'g') {
            if (preg_match('/\(g\)\s+EXECUTIVE\s+\(£([\d,.]+)\)/i', $savedBooking, $matches)) {
                $recommendedPrice = $matches[1];
                saveConfirmedBooking($sender, $previousBooking, 'g', $wamid, $contextId, $lats, $lngs, $token);
                return "Great choice! Executive Vehicle ( £$recommendedPrice ), Your taxi will be with you right on time.";
            }
        }

        if (strtolower($userMessage) === 'cancel') {
            //updateBooking($sender, 3);
            sendXmppMessage($userMessage, $lats, $lngs, $senderName, $sender, $token, $wamid, "cancel a booking", $contextId);
            return "Your taxi booking has been successfully cancelled.";
        }
        
        if (strtolower($userMessage) === 'edit') {
            sendXmppMessage($userMessage, $lats, $lngs, $senderName, $sender, $token, $wamid, "update a booking", $contextId);
            return "You are welcome to add any additional items to your booking as needed";
        }


    } 

    // Generate booking details with OpenAI // 
    $apiUrl = 'https://api.openai.com/v1/chat/completions';
    $data = [
        'model' => 'gpt-4',
        'messages' => [
            [
                'role' => 'system',
                'content' => '
                    You are a taxi booking assistant. Your task is to analyze the user message and classify it as one of the following actions:
                        - make a booking  
                        - cancel a booking 
                        - update a booking  
                        - confirm booking  
                        - find nearest
                    If the user is asking about something nearby, such as "nearest airport", "where is the nearest station", or "closest taxi rank", respond with: find nearest.
                    If the user is confirming a booking — such as saying "I confirm", "that’s fine", or "I’ll take the taxi" — respond with: confirm booking.
                    Only respond with one of these exact outputs and nothing else, in case none of the options above then respond with: error'
            ],
            [
                'role' => 'user',
                'content' => $userMessage
            ]
        ],
        'temperature' => 0,
        'max_tokens' => 200,
        'top_p' => 1.0,
        'frequency_penalty' => 0.0,
        'presence_penalty' => 0.0
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openai_key
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $reply = $result['choices'][0]['message']['content'] ?? "Sorry, I couldn't understand that.";

    // Log OpenAI response
    file_put_contents('users/openai_response_log.txt', print_r([
        'user_input' => $userMessage,
        'openai_reply' => $reply,
        'raw_response' => $response,
        'error' => $error
    ], true), FILE_APPEND);

    if ($reply == 'error') {
        return "Hi ".$senderName.", I do not understand your request.";
    }else{
        sendXmppMessage($userMessage, $lats, $lngs, $senderName, $sender, $token, $wamid, $reply, $contextId);
        exit;
    }
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

    file_put_contents('whatsapp_response.txt', "To: $to\nMessage: $message\nHTTP Code: $http_code\nResponse: $response\nCurl Error: $error\n\n", FILE_APPEND);
}

function sendWhatsAppVideoFromFile($to, $message, $token, $phone_number_id) {
    $videoPath = "users/how.mp4";
    // 1. Upload video to get media ID
    $uploadUrl = "https://graph.facebook.com/v18.0/{$phone_number_id}/media";
    $video = curl_file_create($videoPath, 'video/mp4', basename($videoPath));

    $uploadData = [
        'file' => $video,
        'type' => 'video',
        'messaging_product' => 'whatsapp'
    ];

    $ch = curl_init($uploadUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $uploadData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $uploadResponse = curl_exec($ch);
    curl_close($ch);

    $uploadResult = json_decode($uploadResponse, true);

    if (!isset($uploadResult['id'])) {
        file_put_contents('whatsapp_response.txt', "Video upload failed: $uploadResponse\n\n", FILE_APPEND);
        return;
    }

    $mediaId = $uploadResult['id'];

    // 2. Send video message
    $messageUrl = "https://graph.facebook.com/v18.0/{$phone_number_id}/messages";
    $videoPayload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'video',
        'video' => [
            //'id' => $mediaId,
            'link' => 'https://dev.ishopay.com/project/taxi-max/system/users/how.mp4',
            'caption' => 'How to send your location'
        ]
    ];

    $ch = curl_init($messageUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($videoPayload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    file_put_contents('whatsapp_response.txt', "Video To: $to\nHTTP Code: $http_code\nResponse: $response\nCurl Error: $error\n\n", FILE_APPEND);
}

function saveUserPlace($sender, $label, $address) {
    $file = 'users/places.json';
    $places = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    if (!isset($places[$sender])) {
        $places[$sender] = [];
    }

    $places[$sender][$label] = $address;

    file_put_contents($file, json_encode($places, JSON_PRETTY_PRINT));
}

function getUserPlaces($sender) {;
    $file = 'users/places.json';
    if (!file_exists($file)) return [];

    $places = json_decode(file_get_contents($file), true);
    return $places[$sender] ?? [];
}

function saveUser($sender, $newName) {
    $file = 'users/location.json';
    $bookings = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    // Only update if the sender already exists
    if (isset($bookings[$sender])) {
        $bookings[$sender]['name'] = $newName;
        file_put_contents($file, json_encode($bookings, JSON_PRETTY_PRINT));
    }
}


function saveUserMessage($sender, $message) {
    $file = 'users/location.json';
    $bookings = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    // Only update if the sender already exists
    if (isset($bookings[$sender])) {
        $bookings[$sender]['message'] = $message;
        $bookings[$sender]['last_message'] = date('Y-m-d H:i:s');
        file_put_contents($file, json_encode($bookings, JSON_PRETTY_PRINT));
    }
}

function saveConfirmedBooking($sender, $bookingData, $car, $wamid, $contextId, $lats, $lngs, $token) { 
    $file = __DIR__ . '/table/booking.json';

    $allBookings = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    // Try to parse the details using regex
    $pickup = $bookingData['pickup'];
    $destination = $bookingData['destination'];
    $time = $bookingData['time'];
    $passengers = $bookingData['passengers'];
    $luggage = $bookingData['luggage'];
    $requests = $bookingData['special'];
    $systemid = $bookingData['systemid'];
    $name = $bookingData['name'] ?? 'Unknown';
    $now = date('Y-m-d H:i:s');

    $bookingEntry = [
        'sender' => $sender,
        'name' => $name,
        'pickup' => $pickup,
        'destination' => $destination,
        'pickup_time' => $time,
        'passengers' => $passengers,
        'luggage' => $luggage,
        'special_requests' => $requests,
        'confirmed_at' => $now,
        'wamid' => $wamid,
        'status' => 1
    ];

    $allBookings[] = $bookingEntry;

    file_put_contents($file, json_encode($allBookings, JSON_PRETTY_PRINT));

    updateBooking($sender, 1);
    
    //sendXmppConfirmMessage($sender, $name, $pickup, $destination, $time, $passengers, $luggage, $requests, $now, $car, $systemid, $wamid, $contextId);

    sendXmppMessage($car, $lats, $lngs, $name, $sender, $token, $wamid, 'confirm booking', $contextId);

}

function updateBooking($sender, $status) {
    $file = 'users/bookings.json';
    $bookings = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    // Check if the sender exists and status is 0
    if (isset($bookings[$sender]) && isset($bookings[$sender]['status']) && $bookings[$sender]['status'] == 0) {
        if ($status == 1){
            $bookings[$sender]['status'] = 1;
        }elseif ($status == 2){
            $bookings[$sender]['status'] = 1;
        }elseif ($status == 3){
            unset($bookings[$sender]);
        }
        file_put_contents($file, json_encode($bookings, JSON_PRETTY_PRINT));
    }
}



function getDetailFromText($text, $keyword) {
    $pattern = "/{$keyword}:\s*(.*?)(\n|$)/i";
    if (preg_match($pattern, $text, $matches)) {
        return trim($matches[1]);
    }
    return 'N/A';
}


function getBooking($sender) {
    $file = 'users/bookings.json';
    if (!file_exists($file)) return null;

    //$bookings = json_decode(file_get_contents($file), true);
    $bookings = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

    if (isset($bookings[$sender]) && $bookings[$sender]['status'] == 0) {
        return $bookings[$sender];
    }else{
        return 'null';
    }
}

function getLocation($sender) {
    $file = 'users/location.json';
    if (!file_exists($file)) return null;

    $location = json_decode(file_get_contents($file), true);

    return $location[$sender] ?? null;
}


function transcribeWhatsAppAudio($sender, $mediaId, $access_token, $phone_number_id, $openai_key) {
     // Step 1: Get the media URL
     $mediaUrl = "https://graph.facebook.com/v18.0/{$mediaId}";
     $ch = curl_init($mediaUrl);
     curl_setopt($ch, CURLOPT_HTTPHEADER, [
         "Authorization: Bearer {$access_token}"
     ]);
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
     $response = curl_exec($ch);
     $curlError = curl_error($ch);
     curl_close($ch);

     if (!$response) {
        sendWhatsAppMessage($sender, "Error getting media URL: {$curlError}", $access_token, $phone_number_id);
        exit;
    }
 
     $data = json_decode($response, true);
     $url = $data['url'] ?? null;
     if (!$url) {
         //return null;
        sendWhatsAppMessage($sender, "Error: URL not found in media response. Raw response: " . $response, $access_token, $phone_number_id);
        exit;
     }
 
     // Step 2: Download the audio
     $ch = curl_init($url);
     curl_setopt($ch, CURLOPT_HTTPHEADER, [
         "Authorization: Bearer {$access_token}"
     ]);
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
     $audioData = curl_exec($ch);
     $curlError = curl_error($ch);
     curl_close($ch);
 
     if (!$audioData) {
        //return null;
        sendWhatsAppMessage($sender, "Error downloading audio: {$curlError}", $access_token, $phone_number_id);
        exit;
     }
 
     // Save the audio
     $filename = 'audio.ogg';
     if (file_put_contents($filename, $audioData) === false) {
        //return null;
        sendWhatsAppMessage($sender, "Error saving audio file to disk.", $access_token, $phone_number_id);
        exit;
     }
 
     // Step 3: Transcribe using OpenAI Whisper
     $ch = curl_init("https://api.openai.com/v1/audio/transcriptions");
     $postFields = [
         'file' => new CURLFile($filename),
         'model' => 'whisper-1'
     ];
     curl_setopt($ch, CURLOPT_HTTPHEADER, [
         "Authorization: Bearer {$openai_key}"
     ]);
     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
     curl_setopt($ch, CURLOPT_POST, true);
     curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
     $response = curl_exec($ch);
     $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
     $curlError = curl_error($ch);
     curl_close($ch);
 
    if (!$response) {
        sendWhatsAppMessage($sender, "Error sending audio to OpenAI: {$curlError}", $access_token, $phone_number_id);
        exit;
    }

     $result = json_decode($response, true);
 
     if ($httpStatus !== 200 || !isset($result['text'])) {
         $errorMessage = $result['error']['message'] ?? 'Unknown error during transcription.';
         sendWhatsAppMessage($sender, "Transcription failed: HTTP {$httpStatus}, Error: {$errorMessage}, Raw response: {$response}", $access_token, $phone_number_id);
         exit;
     }
 
    sendWhatsAppMessage($sender, "Hello! We’ve received your audio message. \n\nYou said: \"" . $result['text'] . "\".\n\nPlease wait a moment while we process your request. Thank you for your patience.", $access_token, $phone_number_id);

    return $result['text'];
}

function sendXmppMessage($userMessage, $lats, $lngs, $senderName, $sender, $id, $wamid, $reply, $contextId){
    $xmppUser = '****'; // without domain
    $xmppPassword = '*************';
    $xmppDomain = 'jabber.hot-chilli.net';
    $recipient = '***********@jabber.hot-chilli.net';
    $nearestCompany = getNearestCompanyId($lats, $lngs) ?? ['id' => null, 'name' => null];
    $companyName = $nearestCompany['name'] ?? 'N/A';
    $companyId = $nearestCompany['id'] ?? 'N/A';


    $xmlMessages = "
    <passengermessage>
        <usermessage>${userMessage}</usermessage>
        <userlat>${lats}</userlat>
        <userlon>${lngs}</userlon>
        <username>${senderName}</username>
        <usertelephone>${sender}</usertelephone>
        <systemid>${id}</systemid>
        <intent>${reply}</intent>
        <wamid>${wamid}</wamid>
        <contextId>${contextId}</contextId>
        <companyName>${companyName}</companyName>
        <companyId>${companyId}</companyId>
        <recipient>${recipient}</recipient>
    </passengermessage>
    ";

    $dataToLog = [
        'usermessage' => $userMessage,
        'userlat' => $lats,
        'userlon' => $lngs,
        'username' => $senderName,
        'usertelephone' => $sender,
        'systemid' => $id,
        'intent' => $reply,
        'wamid' => $wamid,
        'contextId' => $contextId,
        'companyName' => $companyName,
        'companyId' => $companyId,

        'timestamp' => date('Y-m-d H:i:s'),
    ];
    $logFile = 'users/message_sent_log.txt';
    // Append each JSON object on a new line
    file_put_contents($logFile, json_encode($dataToLog, JSON_PRETTY_PRINT) . "\n", FILE_APPEND); 

    // Connect using BOSH
    $options = new Options('tcp://jabber.hot-chilli.net:5222');
    $options->setUsername($xmppUser)
        ->setPassword($xmppPassword)
        //->setHost($xmppDomain)
        ->setTo($xmppDomain);
        //->setUseEncryption(false); // BOSH doesn't need encryption

    $client = new Client($options);
    $client->connect();

    $message = new Message;
    $message->setMessage($xmlMessages)
        ->setTo($recipient);

    $client->send($message);
    $client->disconnect();
    
    //exit;
    //return "sent";

} 


function sendXmppConfirmMessage($sender, $name, $pickup, $destination, $time, $passengers, $luggage, $requests, $now, $car, $systemid, $wamid, $contextId){

    $xmppUser = '*****'; // without domain
    $xmppPassword = '************';
    $xmppDomain = 'jabber.hot-chilli.net';
    $recipient = '******@jabber.hot-chilli.net';
    /*
    <passengerbookings>
        <messagetype>amendedbooking<messagetype>
        <sender>${sender}</sender>
        <name>${name}</name>
        <pickup>${pickup}</pickup>
        <destination>${destination}</destination>
        <pickup_time>${time}</pickup_time>
        <passengers>${passengers}</passengers>
        <luggage>${luggage}</luggage>
        <special_requests>${requests}</special_requests>
        <car_type>${car}</car_type>
        <confirmed_at>${now}</confirmed_at>
        <id>${id}</id>
    </passengerbookings>
    */


    $jsonMessage = json_encode([
        "confirmation" => [
            "systemid" => $systemid,
            "VehicleType" => $car,
            "Telephone" => $sender,
            "wamid" => $wamid,
            "contextId" => $contextId,
            "ConfirmationHeader" => "Your taxi booking has been confirmed!"
        ]
    ]);

    // Connect using BOSH
    $options = new Options('tcp://jabber.hot-chilli.net:5222');
    $options->setUsername($xmppUser)
        ->setPassword($xmppPassword)
        ->setTo($xmppDomain);

    $client = new Client($options);
    $client->connect();

    $message = new Message;
    $message->setMessage($jsonMessage)
        ->setTo($recipient);

    $client->send($message);
    $client->disconnect();
    
    //return "sent";

} 

function findNearbyTaxis($lat, $lng) {
    $apiKey = '**********************';

    // Step 1: Search for nearby taxi_stands
    $radius = 20000; // in meters
    $type = 'taxi_stand';
    $url = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location=$lat,$lng&radius=$radius&type=$type&key=$apiKey";

    $response = file_get_contents($url);
    $results = json_decode($response, true);

    if (!$results || $results['status'] !== 'OK') {
        return ['error' => 'No nearby taxi stands found or API request failed.'];
    }

    // Step 2: Loop through results and get detailed info for each place
    $detailedResults = [];

    foreach ($results['results'] as $place) {
        $placeId = $place['place_id'];

        $detailsUrl = "https://maps.googleapis.com/maps/api/place/details/json?place_id=$placeId&fields=name,formatted_address,formatted_phone_number,rating&key=$apiKey";
        $detailsResponse = file_get_contents($detailsUrl);
        $placeDetails = json_decode($detailsResponse, true);

        if ($placeDetails && $placeDetails['status'] === 'OK') {
            $info = $placeDetails['result'];

            $detailedResults[] = [
                'name' => $info['name'] ?? 'N/A',
                'address' => $info['formatted_address'] ?? 'N/A',
                'phone' => $info['formatted_phone_number'] ?? 'N/A',
                'rating' => $info['rating'] ?? 'N/A',
            ];
        }
    }

    return $detailedResults;
}

function getAddressFromCoordinates($lat, $lng) {
    $apiKey = '**********************';

    $url = "https://maps.googleapis.com/maps/api/geocode/json?latlng=$lat,$lng&key=$apiKey";

    $response = file_get_contents($url);
    if ($response === FALSE) {
        return "Error fetching location data.";
    }

    $data = json_decode($response, true);

    if ($data['status'] === 'OK' && !empty($data['results'][0]['formatted_address'])) {
        return $data['results'][0]['formatted_address'];
    } else {
        return "";
    }
}

function getNearestCompanyId($lat, $lon) {
    include "../db/db_connect.php";

    $companies = $conn->query("SELECT * FROM companies") ;
    $taxiCompanies = [];  

    while ($row = $companies->fetch_assoc()) {
        $taxiCompanies[] = [
            'name' => $row['company_name'],
            'lat' => floatval($row['lat']),
            'lon' => floatval($row['lon']),
            'id' => intval($row['id']),
            'jid' => $row['jid']
        ];
    }

    $nearestCompany = null;
    $shortest = PHP_INT_MAX;

    foreach ($taxiCompanies as $company) {
        $dist = getDistance($lat, $lon, $company['lat'], $company['lon']);
        if ($dist < $shortest) {
            $shortest = $dist;
            $nearestCompany = [
                'id' => $company['id'],
                'name' => $company['name']
            ];
        }
    }

    return $nearestCompany;
}



function getDistance($lat1, $lon1, $lat2, $lon2) {
    $R = 6371; // Earth radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

?>
