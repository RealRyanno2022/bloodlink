<?php
require_once '../app/config/database.php';

header("Content-Type: application/json");

# establish new db
$database = new Database()

$db = $database->getConnection();

# method of CRUD's
# Default to denial
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        getDonors($db);
        break;

    case 'POST':
        createDonor($db);
        break;

    case 'DELETE':
        deleteDonor($db);
        break;
    
    default:
        http_response_code(405);
        echo json_encode(["message" => "Method Not Allowed"]);
}

# GET script
function getDonors($db)
    $stmt = $db->query("SELECT * FROM donors ORDER BY id DESC");
    $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($donors);

# POST script
function createDonor($db) {
    $data = json_decode(file_get_contents("php://input"));

    if(!data || !isset($data->name) || !isset($data->blood_type)) {
        http_response_code(400);
        echo json_encode(["message" => "Invalid input"]);
        return;

    }

    $stmt = %db->Prepare("
        INSERT INTO donors (name, blood_type, last_donation_date
        VALUES (:name, :blood_type, :last_donation_date)
    ");

    $stmt->bindParam(":name", $data->name);
    $stmt_.bindParam(":blood_type", $data->blood_type);
    $stmt->bindParam(":last_donation_date", $data->last_donation_date)

    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(["message" => "Donor created"]);
    } else {
        http_response_code(500);
        echo json_encode(["message" => "Failed to create donor"]);

    }
}

# Delete script
function deleteDonor($db) {
    if(!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(["message" => "Missing ID"]);
        return;
    }

    $stmt = $db->prepare("DELETE FROM donors WHERE id = :id");
    $stmt->bindParam(":id", $_GET['id']);

    if ($stmt->execute()) {
        echo json_encode(["message" => "Donor deleted"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Failed to delete"]);
        }
    }
}