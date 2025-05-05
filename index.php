<?php
require_once 'autoload.php';

// Конфигурация
$config = [
    'subdomain' => '9307representative',
    'client_id' => 'ID интекрации',
    'client_secret' => 'Секретный ключ',
    'redirect_uri' => 'Ссылка для перенаправления',
    'code' => 'Код авторизации'
];

// Включаем логирование
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function logMessage($message) {
    file_put_contents('webhook.log', date('[Y-m-d H:i:s]') . ' ' . $message . "\n", FILE_APPEND);
}

logMessage('=== NEW REQUEST STARTED ===');

// Получаем данные
$input = file_get_contents('php://input');
logMessage("Raw input: " . $input);

// Парсим данные вебхука
$webhookData = [];
if (json_decode($input)) {
    $webhookData = json_decode($input, true);
    logMessage("Data parsed as JSON");
} else {
    parse_str($input, $parsedData);
    if (isset($parsedData['data']) && json_decode($parsedData['data'])) {
        $webhookData = json_decode($parsedData['data'], true);
        logMessage("Data parsed as form-urlencoded with JSON");
    } else {
        $webhookData = $parsedData;
        logMessage("Data parsed as form-urlencoded");
    }
}

logMessage("Parsed data: " . json_encode($webhookData, JSON_PRETTY_PRINT));

if (empty($webhookData)) {
    logMessage("ERROR: Empty webhook data");
    http_response_code(400);
    die('Invalid webhook data');
}

// Инициализация клиента
try {
    $amoClient = new AmoCrmV4Client(
        $config['subdomain'],
        $config['client_id'],
        $config['client_secret'],
        $config['code'],
        $config['redirect_uri']
    );
    logMessage("Client initialized");
} catch (Exception $e) {
    logMessage("Client init error: " . $e->getMessage());
    http_response_code(500);
    die('Error initializing client');
}

// Обработка события
try {
    // Определяем тип сущности и событие
    $entityInfo = detectEntity($webhookData);
    if (!$entityInfo) {
        throw new Exception('No valid entity found in webhook data');
    }

    list($entityType, $entityId, $eventType) = $entityInfo;
    logMessage("Processing $entityType #$entityId ($eventType)");

    // Получаем текущие данные
    $currentData = $amoClient->GETRequestApi("$entityType/$entityId");
    logMessage("Current entity data: " . json_encode($currentData, JSON_PRETTY_PRINT));

    // Формируем примечание
    $noteText = $eventType === 'created' 
        ? formatCreationNote($entityType, $currentData, $amoClient)
        : formatUpdateNote($entityType, $currentData, $amoClient);

    logMessage("Note text: $noteText");

    // Отправляем примечание
    $noteData = [
        [
            'entity_id' => (int)$entityId,
            'note_type' => 'common',
            'params' => [
                'text' => $noteText
            ]
        ]
    ];

    $result = $amoClient->POSTRequestApi("$entityType/notes", $noteData);
    logMessage("API response: " . json_encode($result, JSON_PRETTY_PRINT));

    http_response_code(200);
    echo 'OK';
} catch (Exception $e) {
    $error = 'ERROR: ' . $e->getMessage();
    logMessage($error);
    http_response_code(500);
    die($error);
}

// Функции
function detectEntity($webhookData) {
    $entityMap = [
        'leads' => ['add', 'update', 'status'],
        'contacts' => ['add', 'update']
    ];

    foreach ($entityMap as $entityType => $events) {
        foreach ($events as $event) {
            if (isset($webhookData[$entityType][$event][0]['id'])) {
                return [
                    $entityType,
                    $webhookData[$entityType][$event][0]['id'],
                    $event === 'add' ? 'created' : 'updated'
                ];
            }
        }
    }

    return null;
}

function formatCreationNote($entityType, $entityData, $amoClient) {
    $entityName = $entityType === 'leads' ? 'Сделка' : 'Контакт';
    $noteParts = ["$entityName создана"];
    
    // Обрабатываем все основные поля
    $noteParts = array_merge($noteParts, processEntityFields($entityType, $entityData, $amoClient));
    
    // Добавляем время создания
    $noteParts[] = "Время создания: " . date('Y-m-d H:i:s', $entityData['created_at'] ?? time());
    
    return implode("\n", $noteParts);
}

function formatUpdateNote($entityType, $entityData, $amoClient) {
    $entityName = $entityType === 'leads' ? 'Сделка' : 'Контакт';
    $noteParts = ["$entityName обновлена"];
    
    // Обрабатываем все основные поля
    $noteParts = array_merge($noteParts, processEntityFields($entityType, $entityData, $amoClient));
    
    // Добавляем время изменения
    $noteParts[] = "Время изменения: " . date('Y-m-d H:i:s', $entityData['updated_at'] ?? time());
    
    return implode("\n", $noteParts);
}

function processEntityFields($entityType, $entityData, $amoClient) {
    $result = [];
    
    // Общие поля для всех сущностей
    $commonFields = [
        'name' => 'Название',
        'responsible_user_id' => 'Ответственный',
        'created_at' => 'Дата создания',
        'updated_at' => 'Дата изменения'
    ];
    
    // Специфичные поля для сделок
    $leadFields = [
        'price' => 'Цена',
        'status_id' => 'Статус',
        'pipeline_id' => 'Воронка',
        'loss_reason_id' => 'Причина отказа',
        'source_id' => 'Источник',
        'company_id' => 'Компания'
    ];
    
    // Специфичные поля для контактов
    $contactFields = [
        'first_name' => 'Имя',
        'last_name' => 'Фамилия',
        'position' => 'Должность',
        'phone' => 'Телефон',
        'email' => 'Email',
        'company_name' => 'Компания'
    ];
    
    // Объединяем поля в зависимости от типа сущности
    $allFields = $commonFields;
    if ($entityType === 'leads') {
        $allFields = array_merge($allFields, $leadFields);
    } else {
        $allFields = array_merge($allFields, $contactFields);
    }
    
    // Обрабатываем каждое поле
    foreach ($allFields as $field => $label) {
        if (!array_key_exists($field, $entityData)) continue;
        
        $value = $entityData[$field];
        
        // Специальная обработка для некоторых полей
        switch ($field) {
            case 'responsible_user_id':
                if (!empty($value)) {
                    $user = $amoClient->GETRequestApi("users/$value");
                    $value = $user['name'] ?? "ID $value";
                } else {
                    $value = 'не указан';
                }
                break;
                
            case 'status_id':
                if ($entityType === 'leads' && isset($entityData['pipeline_id'])) {
                    $value = getStatusName($value, $entityData['pipeline_id'], $amoClient);
                }
                break;
                
            case 'pipeline_id':
                if ($entityType === 'leads') {
                    $value = getPipelineName($value, $amoClient);
                }
                break;
                
            case 'company_id':
                if (!empty($value)) {
                    $company = $amoClient->GETRequestApi("companies/$value");
                    $value = $company['name'] ?? "ID $value";
                }
                break;
        }
        
        // Форматируем значение
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif ($value === null) {
            $value = 'не указано';
        } elseif ($value === '') {
            $value = 'пусто';
        }
        
        $result[] = "$label: $value";
    }
    
    // Обработка кастомных полей
    if (isset($entityData['custom_fields_values'])) {
        foreach ($entityData['custom_fields_values'] as $field) {
            $fieldName = $field['field_name'] ?? 'Кастомное поле';
            $fieldValues = array_map(function($value) {
                return $value['value'] ?? 'нет значения';
            }, $field['values'] ?? []);
            
            $result[] = "$fieldName: " . implode(', ', $fieldValues);
        }
    }
    
    return $result;
}

function getStatusName($statusId, $pipelineId, $amoClient) {
    if (!$pipelineId) return "ID $statusId";
    
    try {
        $statuses = $amoClient->GETRequestApi("leads/pipelines/$pipelineId/statuses");
        foreach ($statuses['_embedded']['statuses'] ?? [] as $status) {
            if ($status['id'] == $statusId) {
                return $status['name'];
            }
        }
    } catch (Exception $e) {
        logMessage("Error getting status name: " . $e->getMessage());
    }
    
    return "ID $statusId";
}

function getPipelineName($pipelineId, $amoClient) {
    try {
        $pipeline = $amoClient->GETRequestApi("leads/pipelines/$pipelineId");
        return $pipeline['name'] ?? "ID $pipelineId";
    } catch (Exception $e) {
        logMessage("Error getting pipeline name: " . $e->getMessage());
        return "ID $pipelineId";
    }
}

logMessage('=== REQUEST COMPLETED ===');