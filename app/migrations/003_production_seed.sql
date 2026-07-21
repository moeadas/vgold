-- VGo Migration 003 — Schema fixes + Seed Data for Production

-- Add parent_id to projects (for categories/sub-projects)
ALTER TABLE `projects` ADD COLUMN `parent_id` INT NULL AFTER `workspace_id`;
ALTER TABLE `projects` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
ALTER TABLE `projects` ADD INDEX `idx_parent` (`parent_id`);

-- Update task status enum
ALTER TABLE `tasks` MODIFY COLUMN `status` ENUM('todo','in_progress','canceled','completed') DEFAULT 'todo';
ALTER TABLE `tasks` MODIFY COLUMN `priority` ENUM('normal','urgent') DEFAULT 'normal';

-- Update project health enum
ALTER TABLE `projects` MODIFY COLUMN `health` ENUM('on_track','at_risk','blocked','completed','cancelled') DEFAULT 'on_track';

-- Add deadline_date to tasks
ALTER TABLE `tasks` ADD COLUMN `deadline_date` DATE NULL AFTER `due_date`;

-- Add sort_order to tasks
ALTER TABLE `tasks` ADD COLUMN `sort_order` INT DEFAULT 0 AFTER `ai_flagged`;

-- Add columns to project_members
ALTER TABLE `project_members` DROP COLUMN `role`;
ALTER TABLE `project_members` ADD COLUMN `role` VARCHAR(60) DEFAULT '' AFTER `user_id`;

-- Seed users (5 team members)
-- SECURITY: passwords are intentionally INVALID/unusable hashes so these demo accounts
-- cannot be logged into until an admin sets real passwords (see 001_init.sql note).
INSERT INTO `users` (`id`, `name`, `email`, `password`, `avatar_color`, `role`) VALUES
(2, 'Dana Kim', 'dana@northwind.studio', 'DISABLED-SET-PASSWORD-AFTER-IMPORT', '#8A6D4F', 'member'),
(3, 'Sam Patel', 'sam@northwind.studio', 'DISABLED-SET-PASSWORD-AFTER-IMPORT', '#4A7C9B', 'member'),
(4, 'Priya Rao', 'priya@northwind.studio', 'DISABLED-SET-PASSWORD-AFTER-IMPORT', '#C99520', 'member'),
(5, 'Marcus Lee', 'marcus@northwind.studio', 'DISABLED-SET-PASSWORD-AFTER-IMPORT', '#6B8E5A', 'member');

-- Add all users to workspace
INSERT INTO `workspace_members` (`workspace_id`, `user_id`, `role`) VALUES
(1, 2, 'member'), (1, 3, 'member'), (1, 4, 'member'), (1, 5, 'member');
INSERT INTO `user_settings` (`user_id`) VALUES (2), (3), (4), (5);

-- Add Moe to channels
INSERT INTO `channel_members` (`channel_id`, `user_id`) VALUES (1, 1), (2, 1);

-- Additional channels
INSERT INTO `channels` (`id`, `workspace_id`, `name`, `type`) VALUES
(3, 1, 'sales', 'channel'),
(4, 1, 'marketing', 'channel'),
(5, 1, 'bioinformatics', 'channel'),
(6, 1, 'operations', 'channel');
INSERT INTO `channel_members` (`channel_id`, `user_id`) VALUES
(1, 2), (1, 3), (1, 4), (1, 5),
(2, 2), (2, 3), (2, 4), (2, 5),
(3, 2), (3, 3), (3, 4), (3, 5),
(4, 2), (4, 3), (4, 4), (4, 5),
(5, 2), (5, 3), (5, 4), (5, 5),
(6, 2), (6, 3), (6, 4), (6, 5);

-- ===== CATEGORIES (parent projects) =====
INSERT INTO `projects` (`id`, `workspace_id`, `parent_id`, `name`, `description`, `color`, `health`, `progress`, `due_date`, `created_by`) VALUES
(2, 1, NULL, 'Sales', 'Pipeline management, lead tracking, and revenue forecasting for Q3 2026.', '#4A7C9B', 'on_track', 65, '2026-07-15', 1),
(3, 1, NULL, 'Marketing', 'Brand refresh, content calendar, and Q3 campaign launches.', '#C99520', 'at_risk', 48, '2026-08-01', 1),
(4, 1, NULL, 'Bioinformatics', 'Genomic data analysis pipeline, variant calling workflow, and clinical reporting tools.', '#6B8E5A', 'on_track', 72, '2026-09-01', 1),
(5, 1, NULL, 'Operations', 'Infrastructure, vendor management, and process automation.', '#8A6D4F', 'on_track', 55, '2026-07-31', 1),
(6, 1, NULL, 'Management', 'Strategic planning, team OKRs, hiring pipeline, and cross-functional coordination.', '#7e6549', 'on_track', 80, '2026-07-10', 1),
(7, 1, NULL, 'Investors', 'Investor relations, due diligence prep, fundraising pipeline, and quarterly reporting.', '#B0432B', 'blocked', 30, '2026-08-15', 1);

-- Category members
INSERT INTO `project_members` (`project_id`, `user_id`, `role`) VALUES
(2, 1, 'Lead'), (2, 4, 'Sales'), (2, 5, 'Analyst'),
(3, 1, 'Lead'), (3, 2, 'Design'), (3, 4, 'Content'),
(4, 1, 'Lead'), (4, 3, 'Engineer'), (4, 5, 'Analyst'),
(5, 1, 'Lead'), (5, 3, 'Ops'), (5, 5, 'Analyst'),
(6, 1, 'Lead'), (6, 2, 'Design Lead'), (6, 4, 'PM'),
(7, 1, 'Lead'), (7, 4, 'Analyst'), (7, 5, 'Analyst'),
(6, 2, 'Member');

-- Also add Dana to Management
INSERT IGNORE INTO `project_members` (`project_id`, `user_id`, `role`) VALUES (6, 2, 'Member');

-- ===== SUB-PROJECTS =====
INSERT INTO `projects` (`id`, `workspace_id`, `parent_id`, `name`, `description`, `color`, `health`, `progress`, `due_date`, `created_by`) VALUES
(15, 1, 3, 'Summer Campaign', 'Q3 summer marketing campaign across social, email, and paid channels.', '#C99520', 'on_track', 25, '2026-07-30', 1),
(16, 1, 3, 'Website Revamp', 'Redesign of main company website with new brand and messaging.', '#C99520', 'on_track', 33, '2026-08-15', 1),
(17, 1, 3, 'Company Profile Design', 'Updated company profile document for sales and investor use.', '#C99520', 'on_track', 0, '2026-07-20', 1),
(18, 1, 2, 'Acme Corp Deal', 'Enterprise deal worth $120K ARR. Final negotiation stage.', '#4A7C9B', 'at_risk', 80, '2026-06-27', 1),
(19, 1, 2, 'Q3 Sales Forecast', 'Pipeline analysis and revenue projections for Q3.', '#4A7C9B', 'on_track', 20, '2026-07-01', 1),
(20, 1, 4, 'Karyotyping Analysis', 'ML-based karyotyping classification model development.', '#6B8E5A', 'on_track', 60, '2026-08-30', 1),
(21, 1, 4, 'Variant Calling Pipeline', 'Optimization of variant calling workflow for faster processing.', '#6B8E5A', 'on_track', 45, '2026-09-01', 1),
(22, 1, 6, 'Q3 OKRs', 'Finalize Q3 objectives and key results across all teams.', '#7e6549', 'on_track', 50, '2026-06-28', 1),
(23, 1, 7, 'Term Sheet Review', 'Review term sheet from TechVentures Partners.', '#B0432B', 'blocked', 10, '2026-07-10', 1),
(24, 1, 7, 'Data Room Setup', 'Compile all due diligence documents for Series B.', '#B0432B', 'at_risk', 60, '2026-07-30', 1),
(25, 1, 5, 'Server Migration', 'Migrate internal tools to new dedicated server.', '#8A6D4F', 'on_track', 40, '2026-07-01', 1);

-- Sub-project members
INSERT INTO `project_members` (`project_id`, `user_id`, `role`) VALUES
(15, 1, 'Lead'), (15, 2, 'Member'), (15, 4, 'Member'),
(16, 1, 'Lead'), (16, 2, 'Member'), (16, 4, 'Member'),
(17, 1, 'Lead'), (17, 2, 'Member'),
(18, 1, 'Lead'), (18, 4, 'Member'),
(19, 1, 'Lead'), (19, 5, 'Member'),
(20, 1, 'Lead'), (20, 3, 'Member'), (20, 5, 'Member'),
(21, 1, 'Lead'), (21, 3, 'Member'),
(22, 1, 'Lead'), (22, 2, 'Member'),
(23, 1, 'Lead'),
(24, 1, 'Lead'), (24, 5, 'Member'),
(25, 1, 'Lead'), (25, 3, 'Member');

-- ===== TASKS for categories =====
INSERT INTO `tasks` (`project_id`, `title`, `status`, `priority`, `assigned_to`, `created_by`, `due_date`, `deadline_date`, `ai_flagged`) VALUES
(2, 'Close Acme Corp deal ($120K ARR)', 'in_progress', 'urgent', 4, 1, '2026-06-27', '2026-06-27', 1),
(2, 'Q3 sales forecast report', 'todo', 'urgent', 5, 1, '2026-07-01', '2026-07-01', 0),
(2, 'Renew Delta Systems contract', 'todo', 'urgent', 4, 1, '2026-07-10', '2026-07-10', 0),
(2, 'Update CRM pipeline stages', 'completed', 'normal', 5, 1, '2026-06-15', '2026-06-15', 0),
(2, 'Onboard new SDR hire', 'completed', 'normal', 4, 1, '2026-06-10', '2026-06-10', 0),
(2, 'Prepare enterprise pitch deck', 'in_progress', 'urgent', 4, 1, '2026-06-30', '2026-06-30', 0),
(3, 'Launch Q3 content calendar', 'in_progress', 'urgent', 4, 1, '2026-07-05', '2026-07-05', 0),
(3, 'Brand refresh — new landing page', 'todo', 'urgent', 2, 1, '2026-07-15', '2026-07-15', 1),
(3, 'Webinar: Bioinformatics 101', 'todo', 'normal', 4, 1, '2026-07-20', '2026-07-20', 0),
(3, 'Press release for Q2 results', 'todo', 'normal', 4, 1, '2026-07-10', '2026-07-10', 0),
(3, 'Social media audit', 'completed', 'normal', 4, 1, '2026-06-01', '2026-06-01', 0),
(3, 'Email automation sequences', 'in_progress', 'normal', 5, 1, '2026-06-28', '2026-06-28', 0),
(4, 'Variant calling pipeline optimization', 'in_progress', 'urgent', 3, 1, '2026-07-15', '2026-07-15', 0),
(4, 'Clinical report template v3', 'in_progress', 'normal', 5, 1, '2026-07-20', '2026-07-20', 0),
(4, 'GATK pipeline validation', 'todo', 'urgent', 3, 1, '2026-07-01', '2026-07-01', 0),
(4, 'Reference genome upgrade to GRCh38', 'todo', 'urgent', 3, 1, '2026-08-15', '2026-08-15', 0),
(4, 'Variant annotation database update', 'completed', 'normal', 5, 1, '2026-06-05', '2026-06-05', 0),
(4, 'Cloud compute cost analysis', 'todo', 'normal', 5, 1, '2026-07-25', '2026-07-25', 0),
(4, 'PI review meeting prep', 'in_progress', 'normal', 1, 1, '2026-06-28', '2026-06-28', 0),
(5, 'Migrate internal tools to new server', 'in_progress', 'urgent', 3, 1, '2026-07-01', '2026-07-01', 0),
(5, 'Vendor contract renewals', 'todo', 'normal', 5, 1, '2026-07-15', '2026-07-15', 0),
(5, 'Automate onboarding checklist', 'todo', 'normal', 3, 1, '2026-07-10', '2026-07-10', 0),
(5, 'Q2 expense report', 'completed', 'normal', 5, 1, '2026-06-10', '2026-06-10', 0),
(5, 'Update disaster recovery plan', 'todo', 'urgent', 3, 1, '2026-07-20', '2026-07-20', 0),
(5, 'Office equipment inventory audit', 'in_progress', 'normal', 5, 1, '2026-06-30', '2026-06-30', 0),
(6, 'Q3 OKR finalization', 'in_progress', 'urgent', 1, 1, '2026-06-28', '2026-06-28', 1),
(6, 'Hiring pipeline — 2 engineer roles', 'in_progress', 'urgent', 1, 1, '2026-07-15', '2026-07-15', 0),
(6, 'Weekly leadership sync notes', 'todo', 'normal', 1, 1, '2026-06-24', '2026-06-29', 0),
(6, 'Board meeting prep — July', 'todo', 'urgent', 1, 1, '2026-07-05', '2026-07-05', 0),
(6, 'Team satisfaction survey', 'completed', 'normal', 4, 1, '2026-06-01', '2026-06-01', 0),
(6, 'Budget approval — Q3', 'in_progress', 'urgent', 5, 1, '2026-06-30', '2026-06-30', 0),
(7, 'Series B data room preparation', 'todo', 'urgent', 1, 1, '2026-07-30', '2026-07-30', 1),
(7, 'Investor update — June', 'in_progress', 'urgent', 4, 1, '2026-06-30', '2026-06-30', 0),
(7, 'Term sheet review — TechVentures', 'todo', 'urgent', 1, 1, '2026-07-10', '2026-07-10', 1),
(7, 'Pitch deck refresh', 'todo', 'urgent', 2, 1, '2026-07-15', '2026-07-15', 0),
(7, 'Investor meeting — Sequoia', 'todo', 'urgent', 1, 1, '2026-07-05', '2026-07-05', 0),
(7, 'Cap table audit', 'completed', 'normal', 5, 1, '2026-06-01', '2026-06-01', 0);

-- Tasks for sub-projects
INSERT INTO `tasks` (`project_id`, `title`, `status`, `priority`, `assigned_to`, `created_by`, `due_date`, `deadline_date`, `ai_flagged`) VALUES
(15, 'Social media content creation', 'todo', 'urgent', 2, 1, '2026-07-10', '2026-07-10', 0),
(15, 'Influencer outreach campaign', 'todo', 'urgent', 4, 1, '2026-07-15', '2026-07-15', 0),
(15, 'Summer sale email blast', 'todo', 'normal', 4, 1, '2026-07-01', '2026-07-01', 0),
(16, 'Homepage hero redesign', 'in_progress', 'urgent', 2, 1, '2026-07-20', '2026-07-20', 0),
(16, 'Pricing page update', 'todo', 'normal', 2, 1, '2026-07-25', '2026-07-25', 0),
(16, 'Mobile responsive fixes', 'completed', 'normal', 2, 1, '2026-06-15', '2026-06-15', 0),
(17, 'Content draft', 'todo', 'normal', 4, 1, '2026-07-05', '2026-07-05', 0),
(17, 'Layout and design', 'todo', 'normal', 2, 1, '2026-07-15', '2026-07-15', 0),
(18, 'Legal review of contract', 'in_progress', 'urgent', 4, 1, '2026-06-25', '2026-06-25', 0),
(18, 'Final pricing negotiation', 'todo', 'urgent', 4, 1, '2026-06-26', '2026-06-26', 0),
(18, 'Contract signature', 'todo', 'urgent', 1, 1, '2026-06-27', '2026-06-27', 0),
(19, 'Pipeline data compilation', 'in_progress', 'normal', 5, 1, '2026-06-28', '2026-06-28', 0),
(19, 'Win/loss analysis', 'todo', 'normal', 5, 1, '2026-06-30', '2026-06-30', 0),
(20, 'Build ML pipeline', 'in_progress', 'urgent', 3, 1, '2026-08-15', '2026-08-15', 0),
(20, 'Training data curation', 'todo', 'normal', 5, 1, '2026-07-30', '2026-07-30', 0),
(20, 'Model evaluation metrics', 'todo', 'normal', 3, 1, '2026-08-20', '2026-08-20', 0),
(21, 'Benchmark current pipeline', 'completed', 'normal', 3, 1, '2026-06-10', '2026-06-10', 0),
(21, 'Parallel processing implementation', 'in_progress', 'urgent', 3, 1, '2026-08-01', '2026-08-01', 0),
(22, 'Review and align OKRs', 'in_progress', 'urgent', 1, 1, '2026-06-28', '2026-06-28', 0),
(22, 'Team lead feedback session', 'todo', 'normal', 1, 1, '2026-06-27', '2026-06-27', 0),
(23, 'Legal counsel coordination', 'todo', 'urgent', 1, 1, '2026-07-05', '2026-07-05', 0),
(23, 'Term sheet redline', 'todo', 'urgent', 1, 1, '2026-07-08', '2026-07-08', 0),
(24, 'Financial documents upload', 'in_progress', 'urgent', 5, 1, '2026-07-15', '2026-07-15', 0),
(24, 'Legal documents organization', 'todo', 'normal', 1, 1, '2026-07-20', '2026-07-20', 0),
(25, 'Backup current server', 'completed', 'normal', 3, 1, '2026-06-20', '2026-06-20', 0),
(25, 'Configure new server', 'in_progress', 'urgent', 3, 1, '2026-06-28', '2026-06-28', 0),
(25, 'Migration test run', 'todo', 'normal', 3, 1, '2026-06-30', '2026-06-30', 0);

-- ===== Project chat messages =====
INSERT INTO `project_chat` (`project_id`, `user_id`, `body`) VALUES
(1, 1, 'Design sign-off is the only blocker left. Let''s push to get this done today.'),
(1, 2, 'Final mockups are in review — will have sign-off by this afternoon.'),
(1, 3, 'Pricing section is wired up. Just need final copy for annual plan.'),
(2, 4, 'Acme Corp deal is at 80% probability. Legal is reviewing the MSA now.'),
(2, 1, 'Great. What''s the timeline for signature?'),
(2, 4, 'They want to close by end of week. I''ll follow up daily.'),
(3, 2, 'The landing page redesign is blocked on brand guidelines approval. Who can sign off?'),
(3, 1, 'I''ll review today. Send me the link.'),
(4, 3, 'GATK pipeline is running 40% faster after the optimization. Looking good.'),
(4, 5, 'Annotation databases are all updated. Ready for the pipeline migration.'),
(5, 3, 'Server migration is scheduled for Saturday 2am. Should be back up by 6am.'),
(5, 1, 'Perfect. Send out the maintenance window notice to the team.'),
(6, 1, 'Q3 OKRs are almost finalized. Need each lead to review their team''s objectives by Friday.'),
(7, 1, 'Data room is 60% ready but we''re blocked on financial statements from finance.'),
(7, 5, 'I''ll follow up with finance today. They said the statements would be ready by Wednesday.');

-- ===== Channel messages =====
INSERT INTO `messages` (`channel_id`, `user_id`, `body`) VALUES
(1, 4, 'Morning everyone! Big week ahead — let''s crush it.'),
(1, 1, 'Priority this week: Rivera launch, Acme deal close, and Q3 OKR finalization.'),
(1, 3, 'Server migration is happening Saturday. Minimal downtime expected.'),
(1, 5, 'Q2 expense report is submitted. No surprises there.'),
(2, 2, 'Anyone have good stock photos for the new landing page?'),
(2, 4, 'I have some from Unsplash. Will share the collection link.'),
(3, 4, 'Sales team crushed it last week — 3 new enterprise deals moved to negotiation.'),
(3, 1, 'Excellent. Keep the momentum going.'),
(5, 3, 'The GATK optimization is working beautifully. 40% faster processing.'),
(5, 5, 'All annotation databases updated to latest versions. Ready for migration.'),
(6, 1, 'Reminder: leadership sync every Monday at 9am. Send your updates by Friday EOD.');

-- ===== Task comments =====
INSERT INTO `task_comments` (`task_id`, `user_id`, `body`) VALUES
(1, 1, 'Dana, this is the only thing holding the build. Anything I can do to speed it up?'),
(1, 2, 'Final mockups are in review now — I''ll have sign-off to you first thing tomorrow.'),
(6, 4, 'Acme is ready to sign. Just waiting on legal to clear the MSA language.'),
(6, 1, 'Great work. Keep the pressure on — we need this closed by Friday.'),
(13, 2, 'Brand guidelines are blocking the landing page redesign. Can we get these approved ASAP?'),
(13, 1, 'I''ll review and approve today. Send me the document link.'),
(37, 1, 'We need the financial statements from finance before we can complete the data room.'),
(37, 5, 'I''ll follow up with finance today. They committed to Wednesday delivery.');