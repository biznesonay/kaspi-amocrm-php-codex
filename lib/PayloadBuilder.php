<?php
declare(strict_types=1);

/**
 * Build amoCRM contact payload with optional custom fields and tags.
 */
function amoBuildContactPayload(
    string $firstName,
    string $lastName,
    ?int $responsibleUserId,
    array $customFields,
    array $tags
): array {
    $payload = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'responsible_user_id' => $responsibleUserId,
    ];

    if ($customFields) {
        $payload['custom_fields_values'] = array_values($customFields);
    }

    if ($tags) {
        $payload['_embedded']['tags'] = array_values($tags);
    }

    return $payload;
}

/**
 * Build amoCRM lead payload with embedded contacts and tags.
 */
function amoBuildLeadPayload(
    string $name,
    int $price,
    ?int $pipelineId,
    ?int $statusId,
    ?int $responsibleUserId,
    array $customFields,
    array $embeddedContacts,
    array $embeddedTags
): array {
    $payload = [
        'name' => $name,
        'price' => $price,
        'pipeline_id' => $pipelineId,
        'status_id' => $statusId,
        'responsible_user_id' => $responsibleUserId,
    ];

    if ($customFields) {
        $payload['custom_fields_values'] = array_values($customFields);
    }

    $embedded = [
        'contacts' => $embeddedContacts ? array_values($embeddedContacts) : [],
    ];

    if ($embeddedTags) {
        $embedded['tags'] = array_values($embeddedTags);
    }

    if ($embedded) {
        $payload['_embedded'] = $embedded;
    }

    return $payload;
}
