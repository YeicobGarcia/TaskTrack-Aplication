<?php

require 'vendor/autoload.php';

use Aws\DynamoDb\DynamoDbClient;
use Aws\Exception\AwsException;

header("Content-Type: application/json");

$client = new DynamoDbClient([
    'region' => 'us-east-1',
    'version' => 'latest'
]);

$tableName = 'Tasks';

// Manejo de rutas de la API
$uri = $_SERVER['REQUEST_URI'];
$path = explode('/', trim($uri, '/'));
$requestMethod = $_SERVER["REQUEST_METHOD"];

$task_id_index = 2;
$task_id = isset($path[$task_id_index]) ? $path[$task_id_index] : null;

error_log("Full Path: " . print_r($path, true));
error_log("Extracted Task ID: " . $task_id);

switch ($requestMethod) {
    case 'POST':
        createTask();
        break;
    case 'GET':
        if ($task_id) { 
            // Obtener tarea específica por ID
            error_log("Getting Task ID (GET): " . $task_id);
            getTaskById($task_id);
        } else {
            // Obtener todas las tareas
            getTasks();
        }
        break;
    case 'PUT':
        if ($task_id) { 
            error_log("Updating Task ID (PUT): " . $task_id);
            updateTaskStatus($task_id);
        } else {
            echo json_encode(['error' => 'El task_id no fue proporcionado en la URL']);
        }
        break;
    case 'DELETE':
        if ($task_id) { 
            error_log("Deleting Task ID (DELETE): " . $task_id);
            deleteTask($task_id);
        } else {
            echo json_encode(['error' => 'El task_id no fue proporcionado en la URL']);
        }
        break;
    default:
        echo json_encode(['message' => 'Método no soportado']);
}

// Función para crear una tarea
function createTask() {
    global $client, $tableName;

    $data = json_decode(file_get_contents("php://input"), true);

    try {
        $result = $client->scan([
            'TableName' => $tableName,
            'ProjectionExpression' => 'task_id'
        ]);

        $maxId = 0;
        foreach ($result['Items'] as $item) {
            $currentId = intval($item['task_id']['S']);
            if ($currentId > $maxId) {
                $maxId = $currentId;
            }
        }

        $newTaskId = str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);
    
        $createdAt = date('Y-m-d H:i:s');

        $client->putItem([
            'TableName' => $tableName,
            'Item' => [
                'task_id' => ['S' => $newTaskId],
                'title' => ['S' => $data['title']],
                'description' => ['S' => $data['description']],
                'status' => ['S' => 'pendiente'],
                'created_at' => ['S' => $createdAt] 
            ]
        ]);

        echo json_encode(['message' => 'Tarea creada', 'task_id' => $newTaskId, 'created_at' => $createdAt]);
    } catch (AwsException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Función para obtener todas las tareas
function getTasks() {
    global $client, $tableName;

    try {
        $result = $client->scan([
            'TableName' => $tableName
        ]);
        $tasks = array_map(function($item) {
            return [
                'task_id' => $item['task_id']['S'],
                'title' => $item['title']['S'],
                'description' => $item['description']['S'],
                'status' => $item['status']['S'],
                'created_at' => $item['created_at']['S'] 
            ];
        }, $result['Items']);
        echo json_encode($tasks);
    } catch (AwsException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Función para obtener una tarea específica por ID
function getTaskById($task_id) {
    global $client, $tableName;

    try {
        $result = $client->getItem([
            'TableName' => $tableName,
            'Key' => [
                'task_id' => ['S' => $task_id]
            ]
        ]);

        if (isset($result['Item'])) {
            $task = [
                'task_id' => $result['Item']['task_id']['S'],
                'title' => $result['Item']['title']['S'],
                'description' => $result['Item']['description']['S'],
                'status' => $result['Item']['status']['S'],
                'created_at' => $result['Item']['created_at']['S']
            ];
            echo json_encode($task);
        } else {
            echo json_encode(['message' => 'Tarea no encontrada']);
        }
    } catch (AwsException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Función para actualizar el estado de una tarea
function updateTaskStatus($task_id) {
    global $client, $tableName;

    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['status'])) {
        echo json_encode(['error' => 'El campo "status" es obligatorio.']);
        return;
    }

    try {
        $result = $client->updateItem([
            'TableName' => $tableName,
            'Key' => ['task_id' => ['S' => $task_id]],
            'UpdateExpression' => 'SET #st = :status',
            'ExpressionAttributeNames' => ['#st' => 'status'],
            'ExpressionAttributeValues' => [':status' => ['S' => $data['status']]],
            'ReturnValues' => 'UPDATED_NEW'
        ]);

        if ($result && isset($result['Attributes'])) {
            echo json_encode([
                'message' => 'Estado de la tarea actualizado',
                'updated_attributes' => $result['Attributes']
            ]);
        } else {
            echo json_encode(['message' => 'No se encontró la tarea o no se actualizó ningún valor.']);
        }
    } catch (AwsException $e) {
        error_log("AWS Exception: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Función para eliminar una tarea
function deleteTask($task_id) {
    global $client, $tableName;

    try {
        $client->deleteItem([
            'TableName' => $tableName,
            'Key' => ['task_id' => ['S' => $task_id]]
        ]);
        echo json_encode(['message' => 'Tarea eliminada']);
    } catch (AwsException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
