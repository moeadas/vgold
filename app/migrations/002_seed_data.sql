-- VGo Comprehensive Seed Data
-- Run after 001_init.sql

-- Users (5 team members)
INSERT INTO `users` (`id`, `name`, `email`, `password`, `avatar_color`, `role`) VALUES
(2, 'Dana Kim', 'dana@northwind.studio', 'DISABLED-SET-PASSWORD-AFTER-IMPORT', '#8A6D4F', 'member'),
(3, 'Sam Patel', 'sam@northwind.studio', 'DISABLED-SET-PASSWORD-AFTER-IMPORT', '#4A7C9B', 'member'),
(4, 'Priya Rao', 'priya@northwind.studio', 'DISABLED-SET-PASSWORD-AFTER-IMPORT', '#C99520', 'member'),
(5, 'Marcus Lee', 'marcus@northwind.studio', 'DISABLED-SET-PASSWORD-AFTER-IMPORT', '#6B8E5A', 'member');

-- Add all users to workspace
INSERT INTO `workspace_members` (`workspace_id`, `user_id`, `role`) VALUES
(1, 2, 'member'), (1, 3, 'member'), (1, 4, 'member'), (1, 5, 'member');
INSERT INTO `user_settings` (`user_id`) VALUES (2), (3), (4), (5);

-- Channels
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

-- ===== PROJECTS (6 categories) =====

-- 1. Sales
INSERT INTO `projects` (`id`, `workspace_id`, `name`, `description`, `color`, `health`, `progress`, `due_date`, `created_by`) VALUES
(2, 1, 'Sales', 'Pipeline management, lead tracking, and revenue forecasting for Q3 2026. Focus on enterprise accounts and expanding renewal rates.', '#4A7C9B', 'on_track', 65, '2026-07-15', 1);
INSERT INTO `project_members` (`project_id`, `user_id`, `role`) VALUES (2, 1, 'Lead'), (2, 4, 'Sales'), (2, 5, 'Analyst');
INSERT INTO `tasks` (`project_id`, `title`, `description`, `status`, `priority`, `assigned_to`, `created_by`, `due_date`, `ai_flagged`) VALUES
(2, 'Close Acme Corp deal ($120K ARR)', 'Final negotiation stage. Legal review pending on the MSA. Need to push for signature by Friday.', 'in_progress', 'urgent', 4, 1, '2026-06-27', 1),
(2, 'Q3 sales forecast report', 'Compile pipeline data from all reps. Include win/loss analysis and revenue projections.', 'todo', 'urgent', 5, 1, '2026-07-01', 0),
(2, 'Renew Delta Systems contract', 'Up for renewal in 60 days. Schedule outreach call and prepare expansion proposal.', 'todo', 'urgent', 4, 1, '2026-07-10', 0),
(2, 'Update CRM pipeline stages', 'Clean up stale deals and reorganize pipeline stages to match new sales process.', 'completed', 'normal', 5, 1, '2026-06-15', 0),
(2, 'Onboard new SDR hire', 'Training materials and first-week schedule for the new SDR starting Monday.', 'completed', 'normal', 4, 1, '2026-06-10', 0),
(2, 'Prepare enterprise pitch deck', 'Tailored deck for enterprise prospects. Highlight ROI case studies and security features.', 'in_progress', 'urgent', 4, 1, '2026-06-30', 0);

-- 2. Marketing
INSERT INTO `projects` (`id`, `workspace_id`, `name`, `description`, `color`, `health`, `progress`, `due_date`, `created_by`) VALUES
(3, 1, 'Marketing', 'Brand refresh, content calendar, and Q3 campaign launches. Coordinating across social, email, and paid channels.', '#C99520', 'at_risk', 48, '2026-08-01', 1);
INSERT INTO `project_members` (`project_id`, `user_id`, `role`) VALUES (3, 1, 'Lead'), (3, 2, 'Design'), (3, 4, 'Content');
INSERT INTO `tasks` (`project_id`, `title`, `description`, `status`, `priority`, `assigned_to`, `created_by`, `due_date`, `ai_flagged`) VALUES
(3, 'Launch Q3 content calendar', 'Blog posts, social media, and email campaigns planned for Q3. Need final approval from leadership.', 'in_progress', 'urgent', 4, 1, '2026-07-05', 0),
(3, 'Brand refresh — new landing page', 'Redesign homepage with new palette and messaging. A/B test hero section variants.', 'todo', 'urgent', 2, 1, '2026-07-15', 1),
(3, 'Webinar: Bioinformatics 101', 'Coordinate with bio team on webinar content. 200+ registrants target.', 'todo', 'normal', 4, 1, '2026-07-20', 0),
(3, 'Press release for Q2 results', 'Draft and distribute press release. Coordinate with PR agency on timing.', 'todo', 'normal', 4, 1, '2026-07-10', 0),
(3, 'Social media audit', 'Review all platforms, identify gaps, and create Q3 strategy document.', 'completed', 'normal', 4, 1, '2026-06-01', 0),
(3, 'Email automation sequences', 'Set up 5 nurture sequences for different lead sources in HubSpot.', 'in_progress', 'normal', 5, 1, '2026-06-28', 0);

-- 3. Bioinformatics
INSERT INTO `projects` (`id`, `workspace_id`, `name`, `description`, `color`, `health`, `progress`, `due_date`, `created_by`) VALUES
(4, 1, 'Bioinformatics', 'Genomic data analysis pipeline, variant calling workflow, and clinical reporting tools development.', '#6B8E5A', 'on_track', 72, '2026-09-01', 1);
INSERT INTO `project_members` (`project_id`, `user_id`, `role`) VALUES (4, 1, 'Lead'), (4, 3, 'Engineer'), (4, 5, 'Analyst');
INSERT INTO `tasks` (`project_id`, `title`, `description`, `status`, `priority`, `assigned_to`, `created_by`, `due_date`, `ai_flagged`) VALUES
(4, 'Variant calling pipeline optimization', 'Reduce processing time from 8h to 4h using parallel processing. Currently testing on 1000 Genomes data.', 'in_progress', 'urgent', 3, 1, '2026-07-15', 0),
(4, 'Clinical report template v3', 'Update report format to include pharmacogenomic insights and pathogenicity classifications.', 'in_progress', 'normal', 5, 1, '2026-07-20', 0),
(4, 'GATK pipeline validation', 'Run validation suite on updated GATK version. Compare against reference outputs.', 'todo', 'urgent', 3, 1, '2026-07-01', 0),
(4, 'Reference genome upgrade to GRCh38', 'Migrate all pipelines from GRCh37 to GRCh38. Update annotation databases.', 'todo', 'urgent', 3, 1, '2026-08-15', 0),
(4, 'Variant annotation database update', 'Update ClinVar, gnomAD, and COSMIC databases to latest versions.', 'completed', 'normal', 5, 1, '2026-06-05', 0),
(4, 'Cloud compute cost analysis', 'Review AWS spot instance usage and identify cost savings. Target 30% reduction.', 'todo', 'normal', 5, 1, '2026-07-25', 0),
(4, 'PI review meeting prep', 'Prepare slides for principal investigator quarterly review. Include throughput metrics and error rates.', 'in_progress', 'normal', 1, 1, '2026-06-28', 0);

-- 4. Operations
INSERT INTO `projects` (`id`, `workspace_id`, `name`, `description`, `color`, `health`, `progress`, `due_date`, `created_by`) VALUES
(5, 1, 'Operations', 'Infrastructure, vendor management, and process automation. Keeping the lights on and making things run smoother.', '#8A6D4F', 'on_track', 55, '2026-07-31', 1);
INSERT INTO `project_members` (`project_id`, `user_id`, `role`) VALUES (5, 1, 'Lead'), (5, 3, 'Ops'), (5, 5, 'Analyst');
INSERT INTO `tasks` (`project_id`, `title`, `description`, `status`, `priority`, `assigned_to`, `created_by`, `due_date`, `ai_flagged`) VALUES
(5, 'Migrate internal tools to new server', 'Move 5 internal apps to the new dedicated server. Minimize downtime with weekend migration window.', 'in_progress', 'urgent', 3, 1, '2026-07-01', 0),
(5, 'Vendor contract renewals', 'Review and renegotiate 3 vendor contracts: AWS, Slack, and Jira. Target 15% cost reduction.', 'todo', 'normal', 5, 1, '2026-07-15', 0),
(5, 'Automate onboarding checklist', 'Create automated onboarding flow in HRIS. Reduce manual steps from 12 to 4.', 'todo', 'normal', 3, 1, '2026-07-10', 0),
(5, 'Q2 expense report', 'Compile and submit Q2 expense analysis to finance team.', 'completed', 'normal', 5, 1, '2026-06-10', 0),
(5, 'Update disaster recovery plan', 'Annual DR plan review. Test backup restoration and update contact lists.', 'todo', 'urgent', 3, 1, '2026-07-20', 0),
(5, 'Office equipment inventory audit', 'Complete physical inventory of all equipment. Reconcile with asset tracking system.', 'in_progress', 'normal', 5, 1, '2026-06-30', 0);

-- 5. Management
INSERT INTO `projects` (`id`, `workspace_id`, `name`, `description`, `color`, `health`, `progress`, `due_date`, `created_by`) VALUES
(6, 1, 'Management', 'Strategic planning, team OKRs, hiring pipeline, and cross-functional coordination. Keeping everyone aligned and moving forward.', '#7e6549', 'on_track', 80, '2026-07-10', 1);
INSERT INTO `project_members` (`project_id`, `user_id`, `role`) VALUES (6, 1, 'Lead'), (6, 2, 'Design Lead'), (6, 4, 'PM');
INSERT INTO `tasks` (`project_id`, `title`, `description`, `status`, `priority`, `assigned_to`, `created_by`, `due_date`, `ai_flagged`) VALUES
(6, 'Q3 OKR finalization', 'Finalize Q3 objectives and key results across all teams. Get buy-in from each team lead.', 'in_progress', 'urgent', 1, 1, '2026-06-28', 1),
(6, 'Hiring pipeline — 2 engineer roles', 'Review candidates for Senior Bioinformatics Engineer and Full Stack Developer positions.', 'in_progress', 'urgent', 1, 1, '2026-07-15', 0),
(6, 'Weekly leadership sync notes', 'Prepare agenda and notes for Monday leadership sync. Include updates from all project leads.', 'todo', 'normal', 1, 1, '2026-06-24', 0),
(6, 'Board meeting prep — July', 'Prepare board deck with Q2 results, Q3 forecast, and strategic initiatives update.', 'todo', 'urgent', 1, 1, '2026-07-05', 0),
(6, 'Team satisfaction survey', 'Send out quarterly team satisfaction pulse survey. Review results and action items.', 'completed', 'normal', 4, 1, '2026-06-01', 0),
(6, 'Budget approval — Q3', 'Review and approve Q3 department budgets. Submit to finance by July 1st.', 'in_progress', 'urgent', 5, 1, '2026-06-30', 0);

-- 6. Investors
INSERT INTO `projects` (`id`, `workspace_id`, `name`, `description`, `color`, `health`, `progress`, `due_date`, `created_by`) VALUES
(7, 1, 'Investors', 'Investor relations, due diligence prep, fundraising pipeline, and quarterly reporting. Maintaining transparency and building trust.', '#B0432B', 'blocked', 30, '2026-08-15', 1);
INSERT INTO `project_members` (`project_id`, `user_id`, `role`) VALUES (7, 1, 'Lead'), (7, 4, 'Analyst'), (7, 5, 'Analyst');
INSERT INTO `tasks` (`project_id`, `title`, `description`, `status`, `priority`, `assigned_to`, `created_by`, `due_date`, `ai_flagged`) VALUES
(7, 'Series B data room preparation', 'Compile all due diligence documents. Organize financials, legal, and tech docs in data room.', 'todo', 'urgent', 1, 1, '2026-07-30', 1),
(7, 'Investor update — June', 'Monthly investor newsletter with key metrics, milestones, and challenges. Send to all current investors.', 'in_progress', 'urgent', 4, 1, '2026-06-30', 0),
(7, 'Term sheet review — TechVentures', 'Review term sheet from TechVentures Partners. Coordinate with legal counsel on terms.', 'todo', 'urgent', 1, 1, '2026-07-10', 1),
(7, 'Pitch deck refresh', 'Update pitch deck with Q2 traction data and new case studies. Ready for upcoming investor meetings.', 'todo', 'urgent', 2, 1, '2026-07-15', 0),
(7, 'Investor meeting — Sequoia', 'Scheduled call with Sequoia partner. Prepare updated metrics and strategic narrative.', 'todo', 'urgent', 1, 1, '2026-07-05', 0),
(7, 'Cap table audit', 'Reconcile cap table with legal records. Verify all option grants and transfers.', 'completed', 'normal', 5, 1, '2026-06-01', 0);

-- ===== Project chat messages =====
INSERT INTO `project_chat` (`project_id`, `user_id`, `body`) VALUES
(1, 1, 'Design sign-off is the only blocker left. Let\'s push to get this done today.'),
(1, 2, 'Final mockups are in review — will have sign-off by this afternoon.'),
(1, 3, 'Pricing section is wired up. Just need final copy for annual plan.'),
(2, 4, 'Acme Corp deal is at 80% probability. Legal is reviewing the MSA now.'),
(2, 1, 'Great. What\'s the timeline for signature?'),
(2, 4, 'They want to close by end of week. I\'ll follow up daily.'),
(3, 2, 'The landing page redesign is blocked on brand guidelines approval. Who can sign off?'),
(3, 1, 'I\'ll review today. Send me the link.'),
(4, 3, 'GATK pipeline is running 40% faster after the optimization. Looking good.'),
(4, 5, 'Annotation databases are all updated. Ready for the pipeline migration.'),
(5, 3, 'Server migration is scheduled for Saturday 2am. Should be back up by 6am.'),
(5, 1, 'Perfect. Send out the maintenance window notice to the team.'),
(6, 1, 'Q3 OKRs are almost finalized. Need each lead to review their team\'s objectives by Friday.'),
(7, 1, 'Data room is 60% ready but we\'re blocked on financial statements from finance.'),
(7, 5, 'I\'ll follow up with finance today. They said the statements would be ready by Wednesday.');

-- ===== Channel messages =====
INSERT INTO `messages` (`channel_id`, `user_id`, `body`) VALUES
(1, 4, 'Morning everyone! Big week ahead — let\'s crush it.'),
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
(1, 2, 'Final mockups are in review now — I\'ll have sign-off to you first thing tomorrow.'),
(6, 4, 'Acme is ready to sign. Just waiting on legal to clear the MSA language.'),
(6, 1, 'Great work. Keep the pressure on — we need this closed by Friday.'),
(13, 2, 'Brand guidelines are blocking the landing page redesign. Can we get these approved ASAP?'),
(13, 1, 'I\'ll review and approve today. Send me the document link.'),
(37, 1, 'We need the financial statements from finance before we can complete the data room.'),
(37, 5, 'I\'ll follow up with finance today. They committed to Wednesday delivery.');

-- ===== AI API key for Ollama =====
INSERT INTO `user_api_keys` (`user_id`, `provider`, `api_key`, `base_url`, `model`, `is_active`) VALUES
(1, 'ollama', '', 'http://localhost:11434', 'glm-5.1:cloud', 1);