-- TZPayWay Gateway Database Installation for PTC Scripts / Viserlab

INSERT INTO `gateways` (`code`, `name`, `alias`, `image`, `status`, `gateway_parameters`, `supported_currencies`, `crypto`, `description`, `created_at`, `updated_at`) 
VALUES 
(133, 'TZPAYWAY', 'TZPAYWAY', 'tzpayway.png', 1, '{"api_key":{"title":"API Key","global":true,"value":""},"api_url":{"title":"API Base URL","global":true,"value":"https://tzpayway.com"}}', '{"BDT":"BDT","USD":"USD"}', 0, 'Pay securely via bKash, Nagad, Rocket, Upay, Bank Transfer, and Crypto through TZPayWay.', NOW(), NOW())
ON DUPLICATE KEY UPDATE 
`name` = VALUES(`name`),
`alias` = VALUES(`alias`),
`gateway_parameters` = VALUES(`gateway_parameters`),
`supported_currencies` = VALUES(`supported_currencies`),
`updated_at` = NOW();
