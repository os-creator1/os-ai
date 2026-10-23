-- READ-ONLY verification of the Lumina Aura production account. Changes nothing.
-- Run:  MYSQL_PWD="$DB_PASSWORD" mysql -h"$DB_HOST" -u"$DB_USERNAME" "$DB_DATABASE" -t < verify-lumina.sql
-- Every row must say PASS. "owner" is the ONLY Lumina Aura Workspace; the checks count only that Business's rows.
SET @w := (SELECT id FROM workspaces WHERE name = 'Lumina Aura Events' ORDER BY id LIMIT 1);
SET @b := (SELECT id FROM businesses WHERE workspace_id = @w ORDER BY id LIMIT 1);
SET @s := (SELECT id FROM websites WHERE business_id = @b ORDER BY id LIMIT 1);

SELECT chk, actual, expected, IF(actual = expected, 'PASS', 'FAIL') AS result FROM (
  SELECT '01 exactly one Lumina Workspace' chk, (SELECT COUNT(*) FROM workspaces WHERE name='Lumina Aura Events') actual, 1 expected
  UNION ALL SELECT '02 Business timezone America/Denver', (SELECT COUNT(*) FROM businesses WHERE id=@b AND timezone='America/Denver' AND name='Lumina Aura Events'), 1
  UNION ALL SELECT '03 contacts', (SELECT COUNT(*) FROM contacts WHERE business_id=@b), 51
  UNION ALL SELECT '04 contacts opted out (unsubscribe)', (SELECT COUNT(*) FROM contacts WHERE business_id=@b AND status='unsubscribe'), 1
  UNION ALL SELECT '05 no contact has a dialable synthetic key', (SELECT COUNT(*) FROM contacts WHERE business_id=@b AND phone LIKE 'np-%' AND phone NOT REGEXP '^np-[a-z]{16}$'), 0
  UNION ALL SELECT '06 email-only contacts (np- keys)', (SELECT COUNT(*) FROM contacts WHERE business_id=@b AND phone LIKE 'np-%'), 4
  UNION ALL SELECT '07 opportunities', (SELECT COUNT(*) FROM crm_opportunities WHERE business_id=@b), 37
  UNION ALL SELECT '08   open', (SELECT COUNT(*) FROM crm_opportunities WHERE business_id=@b AND status='open'), 10
  UNION ALL SELECT '09   won', (SELECT COUNT(*) FROM crm_opportunities WHERE business_id=@b AND status='won'), 7
  UNION ALL SELECT '10   lost', (SELECT COUNT(*) FROM crm_opportunities WHERE business_id=@b AND status='lost'), 20
  UNION ALL SELECT '11 every deal has a contact of this Business', (SELECT COUNT(*) FROM crm_opportunities o LEFT JOIN contacts c ON c.id=o.contact_id AND c.business_id=o.business_id WHERE o.business_id=@b AND c.id IS NULL), 0
  UNION ALL SELECT '12 every deal sits in a stage of its own pipeline', (SELECT COUNT(*) FROM crm_opportunities o LEFT JOIN crm_pipeline_stages s ON s.id=o.stage_id AND s.pipeline_id=o.pipeline_id AND s.business_id=o.business_id WHERE o.business_id=@b AND s.id IS NULL), 0
  UNION ALL SELECT '13 pipelines (Inquiries, Bookings)', (SELECT COUNT(*) FROM crm_pipelines WHERE business_id=@b AND name IN ('Inquiries','Bookings')), 2
  UNION ALL SELECT '14 conversations', (SELECT COUNT(*) FROM chat_boxes WHERE business_id=@b), 45
  UNION ALL SELECT '15 native SMS + imported timeline = 733', (SELECT (SELECT COUNT(*) FROM chat_box_messages m JOIN chat_boxes b ON b.id=m.box_id WHERE b.business_id=@b) + (SELECT COUNT(*) FROM imported_timeline_items WHERE business_id=@b)), 733
  UNION ALL SELECT '16 imported SMS rows carry no send state or operation', (SELECT COUNT(*) FROM chat_box_messages m JOIN chat_boxes b ON b.id=m.box_id WHERE b.business_id=@b AND (m.send_status IS NOT NULL OR m.business_messaging_operation_id IS NOT NULL OR m.automation_step_run_id IS NOT NULL OR m.source <> 'ghl_import')), 0
  UNION ALL SELECT '17 no unread imported threads', (SELECT COUNT(*) FROM chat_boxes WHERE business_id=@b AND notification > 0), 0
  UNION ALL SELECT '18 source-id ledger rows (51+37+45+733)', (SELECT COUNT(*) FROM external_import_records WHERE business_id=@b AND source_system='gohighlevel'), 866
  UNION ALL SELECT '19 draft workflows', (SELECT COUNT(*) FROM automation_workflows WHERE business_id=@b AND published_version_id IS NULL), 11
  UNION ALL SELECT '20 published workflows', (SELECT COUNT(*) FROM automation_workflows WHERE business_id=@b AND published_version_id IS NOT NULL), 0
  UNION ALL SELECT '21 workflow enrollments', (SELECT COUNT(*) FROM automation_enrollments WHERE business_id=@b), 0
  UNION ALL SELECT '22 messaging operations (anything sent)', (SELECT COUNT(*) FROM business_messaging_operations WHERE business_id=@b), 0
  UNION ALL SELECT '23 messaging identities / numbers', (SELECT COUNT(*) FROM business_messaging_identities WHERE business_id=@b), 0
  UNION ALL SELECT '24 forms (Check Availability + Event questionnaire)', (SELECT COUNT(*) FROM forms WHERE business_id=@b AND name IN ('Check Availability','Event questionnaire')), 2
  UNION ALL SELECT '25 website exists and is published', (SELECT COUNT(*) FROM websites WHERE id=@s AND published_revision_id IS NOT NULL), 1
  UNION ALL SELECT '26 website pages', (SELECT COUNT(*) FROM website_pages WHERE website_id=@s), 9
  UNION ALL SELECT '27 website videos', (SELECT COUNT(*) FROM website_assets WHERE website_id=@s AND purpose='video'), 3
  UNION ALL SELECT '28 videos live under the persisted directory', (SELECT COUNT(*) FROM website_assets WHERE website_id=@s AND purpose='video' AND path NOT LIKE 'images/websites/%'), 0
  UNION ALL SELECT '29 no custom domain attached', (SELECT COUNT(*) FROM website_domains WHERE website_id=@s), 0
  UNION ALL SELECT '30 custom redirects stored', (SELECT COUNT(*) FROM websites WHERE id=@s AND JSON_LENGTH(custom_redirects) >= 3), 1
  UNION ALL SELECT '31 no Lumina row leaked into another Business', (SELECT COUNT(*) FROM external_import_records WHERE source_system='gohighlevel' AND business_id <> @b), 0
) t ORDER BY chk;

-- Informational (not pass/fail): the owner and the preview address.
SELECT u.email AS owner_login, w.uid AS workspace_uid, b.uid AS business_uid FROM workspaces w JOIN users u ON u.id=w.owner_user_id JOIN businesses b ON b.workspace_id=w.id WHERE w.id=@w;
SELECT CONCAT('https://getmotiongrove.com/sites/', public_id) AS preview_url FROM websites WHERE id=@s;
