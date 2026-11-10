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
        'dpdp'        => 'privacy',
        'gdpr'        => 'privacy',
    ],
    'referer_map'     => [
        '/docs'           => 'architecture',
        '/legal/security' => 'security',
        '/legal/privacy'  => 'privacy',
    ],
    'channels'        => [
        'general'      => [
            'code'                => 'CHN-01',
            'label'               => 'Business & Project Inquiry',
            'heading'             => 'Send an Enterprise Inquiry',
            'sla'                 => '1 Business Day',
            'subject_placeholder' => 'e.g. Enterprise Website Migration or Partnership',
            'message_label'       => 'Project Requirements *',
            'message_placeholder' => 'Tell us about your project, timeline, and goals...',
            'button_text'         => 'Send Inquiry',
            'fields'              => [
                'solution_type' => [
                    'type'          => 'enum',
                    'required'      => false,
                    'options'       => [
                        'Enterprise Website Framework',
                        'Custom Software Platform',
                        'Security & Compliance Audit',
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
            'subject_placeholder' => 'e.g. Zero-Dependency Deployment or Hub Integration',
            'message_label'       => 'Technical Scope *',
            'message_placeholder' => 'Describe your server topology and integration questions...',
            'button_text'         => 'Request Consultation',
            'fields'              => [],
        ],
        'security'     => [
            'code'                => 'CHN-03',
            'label'               => 'Security Vulnerability Report',
            'heading'             => 'Report a Security Issue',
            'sla'                 => '24 Hours (CERT-In Mandate)',
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
            'label'               => 'Data Privacy & DPDP Rights',
            'heading'             => 'Data Principal Rights & Statutory Inquiries',
            'sla'                 => '72 Hours (DPDP Sec 13)',
            'subject_placeholder' => 'e.g. Personal Data Access, Correction, Erasure, or Grievance',
            'message_label'       => 'Request Description & Reference *',
            'message_placeholder' => 'Describe your statutory request under DPDP Act 2023 / GDPR...',
            'button_text'         => 'Submit Data Rights Request',
            'fields'              => [
                'privacy_request_type' => [
                    'type'          => 'enum',
                    'required'      => false,
                    'options'       => [
                        'Access / Summary of Personal Data (DPDP Sec 11)',
                        'Correction or Update of Personal Data (DPDP Sec 12)',
                        'Erasure / Right to be Forgotten (DPDP Sec 12)',
                        'Withdrawal of Consent',
                        'Statutory Grievance Redressal',
                    ],
                    'receipt_label' => 'STATUTORY REQUEST',
                ],
            ],
        ],
    ],
];
