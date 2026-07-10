<?php

declare(strict_types=1);

require_once __DIR__ . '/BitrixClient.php';

/**
 * Перенос пользовательских полей CRM (UF_CRM_*) между порталами Битрикс24.
 */
class UserFieldMigrator
{
    /** Поддерживаемые сущности CRM: код => [название, префикс REST-методов] */
    public const ENTITIES = [
        'lead'    => ['title' => 'Лиды',        'rest' => 'crm.lead.userfield'],
        'deal'    => ['title' => 'Сделки',      'rest' => 'crm.deal.userfield'],
        'contact' => ['title' => 'Контакты',    'rest' => 'crm.contact.userfield'],
        'company' => ['title' => 'Компании',    'rest' => 'crm.company.userfield'],
        'quote'   => ['title' => 'Предложения', 'rest' => 'crm.quote.userfield'],
    ];

    /** Свойства поля, копируемые при создании/обновлении */
    private const COPY_KEYS = [
        'USER_TYPE_ID', 'XML_ID', 'SORT', 'MULTIPLE', 'MANDATORY',
        'SHOW_FILTER', 'SHOW_IN_LIST', 'EDIT_IN_LIST', 'IS_SEARCHABLE',
        'SETTINGS', 'EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL',
        'ERROR_MESSAGE', 'HELP_MESSAGE',
    ];

    /** Типы полей, значения настроек которых ссылаются на объекты исходного портала */
    private const PORTAL_BOUND_TYPES = ['crm_status', 'iblock_element', 'iblock_section', 'crm'];

    private BitrixClient $source;
    private BitrixClient $target;

    public function __construct(BitrixClient $source, BitrixClient $target)
    {
        $this->source = $source;
        $this->target = $target;
    }

    public static function restPrefix(string $entity): string
    {
        if (!isset(self::ENTITIES[$entity])) {
            throw new InvalidArgumentException('Неизвестная сущность: ' . $entity);
        }

        return self::ENTITIES[$entity]['rest'];
    }

    /**
     * Список пользовательских полей сущности на обоих порталах
     * с признаком наличия каждого поля источника на приёмнике.
     */
    public function listFields(string $entity): array
    {
        $rest = self::restPrefix($entity);

        $sourceFields = $this->source->callAll($rest . '.list', ['order' => ['SORT' => 'ASC']]);
        $targetFields = $this->target->callAll($rest . '.list', ['order' => ['SORT' => 'ASC']]);

        $targetByName = [];
        foreach ($targetFields as $field) {
            $targetByName[$field['FIELD_NAME']] = $field;
        }

        $result = [];
        foreach ($sourceFields as $field) {
            $result[] = [
                'id'          => $field['ID'],
                'fieldName'   => $field['FIELD_NAME'],
                'label'       => $this->fieldLabel($field),
                'type'        => $field['USER_TYPE_ID'],
                'multiple'    => ($field['MULTIPLE'] ?? 'N') === 'Y',
                'mandatory'   => ($field['MANDATORY'] ?? 'N') === 'Y',
                'existsOnTarget' => isset($targetByName[$field['FIELD_NAME']]),
                'portalBound' => in_array($field['USER_TYPE_ID'], self::PORTAL_BOUND_TYPES, true),
            ];
        }

        return [
            'fields'      => $result,
            'targetCount' => count($targetFields),
        ];
    }

    /**
     * Перенос выбранных полей. $fieldNames — массив FIELD_NAME (UF_CRM_...).
     * Возвращает результат по каждому полю.
     *
     * @param bool $updateExisting обновлять ли свойства уже существующих на приёмнике полей
     */
    public function migrate(string $entity, array $fieldNames, bool $updateExisting = false): array
    {
        $rest = self::restPrefix($entity);

        $sourceFields = $this->source->callAll($rest . '.list');
        $targetFields = $this->target->callAll($rest . '.list');

        $sourceByName = [];
        foreach ($sourceFields as $field) {
            $sourceByName[$field['FIELD_NAME']] = $field;
        }
        $targetByName = [];
        foreach ($targetFields as $field) {
            $targetByName[$field['FIELD_NAME']] = $field;
        }

        $results = [];
        foreach ($fieldNames as $fieldName) {
            $results[] = $this->migrateOne($rest, $fieldName, $sourceByName, $targetByName, $updateExisting);
        }

        return $results;
    }

    private function migrateOne(
        string $rest,
        string $fieldName,
        array $sourceByName,
        array $targetByName,
        bool $updateExisting
    ): array {
        $base = ['fieldName' => $fieldName];

        if (!isset($sourceByName[$fieldName])) {
            return $base + ['status' => 'error', 'message' => 'Поле не найдено на портале-источнике'];
        }

        try {
            // Полное описание поля (list не отдаёт значения списков LIST)
            $full = $this->source->call($rest . '.get', ['id' => $sourceByName[$fieldName]['ID']])['result'];
            $payload = $this->buildAddPayload($full);

            if (isset($targetByName[$fieldName])) {
                if (!$updateExisting) {
                    return $base + ['status' => 'skipped', 'message' => 'Уже существует на приёмнике (пропущено)'];
                }
                // При обновлении не трогаем LIST, чтобы не задвоить/не удалить значения списка
                unset($payload['LIST'], $payload['FIELD_NAME'], $payload['USER_TYPE_ID'], $payload['MULTIPLE']);
                $this->target->call($rest . '.update', [
                    'id'     => $targetByName[$fieldName]['ID'],
                    'fields' => $payload,
                ]);

                return $base + ['status' => 'updated', 'message' => 'Свойства поля обновлены (значения списков не изменялись)'];
            }

            $newId = $this->target->call($rest . '.add', ['fields' => $payload])['result'];

            $message = 'Создано (ID ' . $newId . ')';
            if (in_array($full['USER_TYPE_ID'], self::PORTAL_BOUND_TYPES, true)) {
                $message .= '. Внимание: тип «' . $full['USER_TYPE_ID']
                    . '» ссылается на объекты портала — проверьте настройки поля на приёмнике';
            }

            return $base + ['status' => 'created', 'message' => $message];
        } catch (BitrixApiException $e) {
            return $base + ['status' => 'error', 'message' => $e->getMessage() . ' [' . $e->errorCode . ']'];
        }
    }

    /**
     * Готовит массив fields для userfield.add на портале-приёмнике.
     */
    private function buildAddPayload(array $field): array
    {
        $payload = [
            // add сам добавляет префикс UF_CRM_, поэтому срезаем его
            'FIELD_NAME' => preg_replace('/^UF_CRM_/', '', $field['FIELD_NAME']),
        ];

        foreach (self::COPY_KEYS as $key) {
            if (array_key_exists($key, $field) && $field[$key] !== null && $field[$key] !== '') {
                $payload[$key] = $field[$key];
            }
        }

        // Значения списочного поля: переносим без ID (ID выдаст приёмник)
        if (($field['USER_TYPE_ID'] ?? '') === 'enumeration' && !empty($field['LIST'])) {
            $payload['LIST'] = array_map(static function (array $item): array {
                $value = [
                    'VALUE' => $item['VALUE'] ?? '',
                    'SORT'  => $item['SORT'] ?? 500,
                    'DEF'   => $item['DEF'] ?? 'N',
                ];
                if (!empty($item['XML_ID'])) {
                    $value['XML_ID'] = $item['XML_ID'];
                }

                return $value;
            }, $field['LIST']);
        }

        return $payload;
    }

    private function fieldLabel(array $field): string
    {
        foreach (['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'] as $key) {
            $label = $field[$key] ?? '';
            if (is_array($label)) {
                $label = $label['ru'] ?? reset($label);
            }
            if (is_string($label) && trim($label) !== '') {
                return trim($label);
            }
        }

        return $field['FIELD_NAME'];
    }
}
