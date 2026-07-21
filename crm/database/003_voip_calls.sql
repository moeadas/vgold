-- Victory Genomics CRM - VoIP Calls Table
-- Migration: 003_voip_calls.sql
-- Creates the voip_calls table for call logging and tracking
-- Note: The dashboard also auto-creates this table if it doesn't exist

CREATE TABLE IF NOT EXISTS voip_calls (
    call_id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NULL,
    user_id INT NULL,
    twilio_call_sid VARCHAR(50) NULL,
    direction ENUM('Inbound','Outbound') DEFAULT 'Outbound',
    from_number VARCHAR(30) NULL,
    to_number VARCHAR(30) NULL,
    status VARCHAR(30) DEFAULT 'Initiated',
    duration_seconds INT DEFAULT 0,
    outcome VARCHAR(30) NULL COMMENT 'Positive, Neutral, Negative, No Response, Voicemail',
    notes TEXT NULL,
    recording_url VARCHAR(512) NULL,
    recording_sid VARCHAR(50) NULL,
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_voip_user (user_id),
    INDEX idx_voip_lead (lead_id),
    INDEX idx_voip_sid (twilio_call_sid),
    INDEX idx_voip_created (created_at),
    INDEX idx_voip_status (status),
    INDEX idx_voip_direction (direction),
    INDEX idx_voip_outcome (outcome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
