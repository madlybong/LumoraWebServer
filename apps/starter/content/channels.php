<?php
declare(strict_types=1);

if (!defined('LUMORA_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

return [
    'default_channel' => 'general',
    'honeypot_mode'   => 'reject',
    'aliases'         => [
        'data-rights' => 'privacy',
        'disclosure'  => 'security',
    ],
    'referer_map'     => [
        '/docs'          => 'architecture',
        '/legal/privacy' => 'privacy',
    ],
    'channels'        => [
        'general'      => [
            'code'                => 'CHN-01',
            'label'               => 'General & Project Inquiry',
            'heading'             => 'Send an Inquiry',
            'sla'                 => '1 Business Day',
            'subject_placeholder' => 'e.g. Website Architecture or Integration Inquiry',
            'message_label'       => 'Project Requirements *',
            'message_placeholder' => 'Tell us about your project, timeline, and goals...',
            'button_text'         => 'Send Inquiry',
            'fields'              => [
                'solution_type' => [
                    'type'          => 'enum',
                    'required'      => false,
                    'options'       => [
                        'Web Application Framework',
                        'Documentation & Content Portal',
                        'Security & Performance Audit',
                    ],
                    'receipt_label' => 'SOLUTION TYPE',
                    'error_message' => 'Please select a valid solution type.',
                ],
            ],
        ],
        'architecture' => [
            'code'                => 'CHN-02',
            'label'               => 'Architecture & Technical Consultation',
            'heading'             => 'Request Technical Consultation',
            'sla'                 => '4 Business Hours',
            'subject_placeholder' => 'e.g. Zero-Dependency Deployment or Webhook Sync',
            'message_label'       => 'Technical Scope *',
            'message_placeholder' => 'Describe your server topology and integration questions...',
            'button_text'         => 'Request Consultation',
            'fields'              => [],
        ],
        'security'     => [
            'code'                => 'CHN-03',
            'label'               => 'Security Vulnerability Report',
            'heading'             => 'Report a Security Issue',
            'sla'                 => '24 Hours',
            'subject_placeholder' => 'Brief summary of the security finding',
            'message_label'       => 'Vulnerability Details & Steps to Reproduce *',
            'message_placeholder' => 'Describe the affected URL or component and steps to reproduce...',
            'button_text'         => 'Submit Security Report',
            'fields'              => [
                'security_component' => [
                    'type'          => 'string',
                    'required'      => false,
                    'min_length'    => 3,
                    'max_length'    => 200,
                    'receipt_label' => 'AFFECTED COMPONENT',
                ],
            ],
        ],
        'privacy'      => [
            'code'                => 'CHN-04',
            'label'               => 'Privacy & Data Requests',
            'heading'             => 'Privacy Rights & Data Requests',
            'sla'                 => '72 Hours',
            'subject_placeholder' => 'e.g. Data Access, Correction, or Deletion Request',
            'message_label'       => 'Request Description & Reference *',
            'message_placeholder' => 'Describe your privacy request and include any receipt ID...',
            'button_text'         => 'Submit Privacy Request',
            'fields'              => [
                'privacy_request_type' => [
                    'type'          => 'enum',
                    'required'      => false,
                    'options'       => [
                        'Access Submitted Personal Data',
                        'Correct or Update Personal Data',
                        'Delete Personal Data',
                        'Withdraw Consent',
                        'General Privacy Question',
                    ],
                    'receipt_label' => 'PRIVACY REQUEST',
                ],
            ],
        ],
    ],
];
