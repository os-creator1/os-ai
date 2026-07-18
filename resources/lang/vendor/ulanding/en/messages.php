<?php

    return [

        // General messages
        'something_went_wrong' => 'Something went wrong. Please try again later.',
        'permission_denied'    => 'You do not have permission to perform this action.',
        'operation_successful' => 'Operation completed successfully!',

        // Global Labels
        'last_modified'        => 'Last Modified',
        'description'          => 'Description', // General description label
        'slug'                 => 'Slug',       // General slug label
        'is_active'            => 'Is Active?', // General active status label
        'add_new'              => 'Add New',    // General add new label
        'edit'                 => 'Edit',       // General edit label
        'back_to_list'         => 'Back to List',
        'select_category'      => '-- Select Category --',
        'uncategorized'        => 'Uncategorized',
        'unknown_user'         => 'Unknown User',
        'confirm_delete_title' => 'Are you sure?', // General confirmation title
        'you'                  => 'You',
        'updated'              => 'Updated',


        // Buttons
        'buttons'              => [
            'open'       => 'Open',
            'reply'      => 'Reply',
            'publish'    => 'Publish',
            'unpublish'  => 'Unpublish',
            'feature'    => 'Feature',
            'unfeature'  => 'Unfeature',
            'deactivate' => 'Deactivate',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Settings
        |--------------------------------------------------------------------------
        */
        'settings'             => [
            'support_desk_name'               => 'Support Desk Name',
            'support_desk_name_help'          => 'Used throughout your support system, in emails, etc.',
            'support_desk_email'              => 'Support Desk Email',
            'support_desk_email_help'         => 'Only used to send out important updates.',
            'title'                           => 'Support Settings', // General title for the settings page
            'ticket_settings'                 => 'Ticket Settings',
            'ticket_tags'                     => 'Ticket Tags',
            'privacy_settings'                => 'Privacy Settings',
            'important_notice_messages'       => 'Important Notice Messages',
            'auto_reassign'                   => 'Automatically reassign tickets to the responding support agent',
            'filter_by_category'              => 'Filter articles list by category inside ticket reply box',
            'disable_public_tickets'          => 'Disable Public Tickets <span class="text-muted small">(Current public tickets will not be affected)</span>',
            'public_tickets_default'          => 'Public tickets are the default (instead of private)',
            'order_by_last_updated'           => 'Order tickets ascending by last updated date',
            'group_by_last_updated'           => 'Group tickets by last update date',
            'enable_autoresponder'            => 'Enable Autoresponder Message',
            'enable_important_notice_message' => 'Enable Important Notice Message',
            'updated_successfully'            => 'Settings updated successfully.',
            'ticket_tag_updated_successfully' => 'Ticket tag updated successfully.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Agents
        |--------------------------------------------------------------------------
        */
        'agents'               => [
            'open_tickets'                       => 'Open Tickets',
            'closed_tickets'                     => 'Closed Tickets',
            'last_signed_in'                     => 'Last Signed In',
            'statistics'                         => 'Statistics',
            'assign_agent'                       => 'Assign Agent',
            'edit_agent'                         => 'Edit Agent',
            'designation'                        => 'Designation',
            'bio'                                => 'Bio',
            'description'                        => 'First, create a role from <a href=":role_url" target="_blank">Create Role</a>, then create an administrator from <a href=":administrator_url" target="_blank">Create Administrator</a>. Assign the created role to the administrator, and finally designate the administrator as a Support Agent.',
            'assigned_successfully'              => 'Agent assigned successfully.',
            'already_assigned_or_no_change'      => 'Agent is already assigned or no change occurred.',
            'never_logged_in'                    => 'Never Logged In',
            'confirm_activate_text'              => 'Do you really want to activate these agents?',
            'confirm_deactivate_text'            => 'Do you really want to deactivate these agents?',
            'confirm_delete_text'                => 'Do you really want to release these agents from support agent?',
            'yes_activate_it'                    => 'Yes, activate it!',
            'yes_deactivate_it'                  => 'Yes, deactivate it!',
            'yes_release_it'                     => 'Yes, release it!',
            'deactivated_successfully'           => 'Agent deactivated successfully.',
            'updated_successfully'               => 'Agent updated successfully.',
            'deleted_successfully'               => 'Release admin user from support agent successfully.',
            'no_valid_selected_for_deactivation' => 'No valid agents selected for deactivation.',
            'activate_selected'                  => 'Activate Selected',
            'activated_multiple_successfully'    => ':count agents have been activated successfully.',
            'deactivate_selected'                => 'Deactivate Selected',
            'deactivated_multiple_successfully'  => ':count agents have been deactivated successfully.',
            'delete_selected'                    => 'Release Selected',
            'deleted_multiple_successfully'      => ':count agents have been released from support agent successfully.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Categories
        |--------------------------------------------------------------------------
        */
        'categories'           => [
            'title_singular'                => 'Support Category',
            'title_plural'                  => 'Support Categories',
            'name'                          => 'Category Name',
            'icon'                          => 'Icon',
            'name_placeholder'              => 'e.g., Technical Issues, Billing Inquiries',
            'slug_placeholder'              => 'e.g., technical-issues',
            'description_placeholder'       => 'Briefly describe the category',
            'add_new'                       => 'Add New Category',
            'edit'                          => 'Edit Category',
            'details'                       => 'Category Details',
            'select_icon'                   => 'Select an Icon',
            'search_icons'                  => 'Search icons...',
            'created_successfully'          => 'Support category created successfully!',
            'updated_successfully'          => 'Support category updated successfully!',
            'deleted_successfully'          => 'Support category deleted successfully!',
            'confirm_activate'              => 'Are you sure you want to activate the selected categories?',
            'confirm_deactivate'            => 'Are you sure you want to deactivate the selected categories?',
            'confirm_delete'                => 'Are you sure you want to permanently delete the selected categories?',
            'activated_successfully'        => ':count categories have been activated successfully.',
            'deactivated_successfully'      => ':count categories have been deactivated successfully.',
            'deleted_multiple_successfully' => ':count categories have been deleted successfully.',
            'slug_auto_generated_help'      => 'Slug will be auto-generated from name if left empty.',
            'browse_by_category'            => 'Browse by Category',
            'published_on'                  => 'Published On',
            'related_questions'             => 'Related Questions',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Tickets
        |--------------------------------------------------------------------------
        */
        'tickets'              => [
            'title_singular'                        => 'Support Ticket',
            'title_plural'                          => 'Support Tickets',
            'create_ticket'                         => 'Create Ticket',
            'view_ticket'                           => 'View Ticket',
            'created_successfully'                  => 'Support ticket created successfully!',
            'updated_successfully'                  => 'Support ticket updated successfully!',
            'deleted_successfully'                  => 'Support ticket deleted successfully!',
            'status_updated_successfully'           => 'Ticket status updated successfully!',
            'assigned_successfully'                 => 'Ticket assigned successfully!',
            'tags_updated_successfully'             => 'Ticket tags updated successfully!',
            'confirm_action'                        => 'You are about to :action :length tickets.',
            'opened_successfully'                   => ':count tickets opened successfully!',
            'closed_successfully'                   => ':count tickets closed successfully!',
            'marked_pending_successfully'           => ':count tickets marked pending successfully!',
            'assigned_multiple_successfully'        => ':count tickets assigned to :agent successfully!',
            'deleted_multiple_successfully'         => ':count tickets deleted successfully!',
            'starred_successfully'                  => 'Ticket starred status updated successfully!',
            'category'                              => 'Category',
            'assigned_agent'                        => 'Assigned Agent',
            'last_replied'                          => 'Last Replied',
            'priority'                              => 'Priority',
            'customer_name'                         => 'Customer Name',
            'customer_email'                        => 'Customer Email',
            'please_select_an_agent_for_assignment' => 'Please select an agent for assignment.',
            'search_placeholder'                    => 'Search Tickets...',
            'filter_by_status'                      => 'Filter by Status',
            'filter_by_priority'                    => 'Filter by Priority',
            'assigned_to'                           => 'Assigned to :agent_name', // :agent_name will be replaced
            'unassigned'                            => 'Unassigned',
            'last_updated'                          => 'Updated :time ago', // :time will be replaced by diffForHumans()
            'no_tickets_found'                      => 'No tickets found matching your criteria.',
            'category_help'                         => 'With which category do you need help?',
            'subject_help'                          => 'In general, what is this ticket about?',
            'subject_placeholder'                   => 'What is this ticket about?',
            'related_url'                           => 'Related URL',
            'related_url_help'                      => 'Optional, but very helpful.',
            'description_help'                      => 'Please be as descriptive as possible regarding the details of this ticket.',
            'description_placeholder'               => 'How can we help you today?',
            'agent_help'                            => 'Optionally assign an agent to this ticket.',
            'select_agent'                          => 'Select an agent',
            'file_type_not_allowed'                 => 'File type not allowed.',
            'file_extension_not_allowed'            => 'File extension not allowed.',
            'file_too_large'                        => 'File is too large. Max :max_size allowed.',
            'one_or_more_files_invalid'             => 'One or more selected files are invalid (type or size). Please check and try again.',
            'max_attachments_exceeded'              => 'You can upload a maximum of :max attachments.',
            'make_public'                           => 'Make this ticket public',
            'is_public_customer_notes'              => 'By default, only the support team can view and respond to your tickets. ',
            'is_public_help'                        => '<i>A public ticket,</i> however, would allow the entire community to view and reply. <b>Note that they cannot view any information entered into the "private" fields above.</b>',
            'status_help'                           => 'Current status of the ticket.',
            'select_status'                         => 'Select status',
            'priority_help'                         => 'Importance level of the ticket.',
            'select_priority'                       => 'Select priority',
            'customer_help'                         => 'Select the customer for whom this ticket is being created.',
            'select_customer'                       => 'Select a customer',
            'private_ticket'                        => 'PRIVATE TICKET',
            'original_description'                  => 'Original Ticket Description',
            'no_replies_yet'                        => 'No replies or notes have been added yet.',
            'post_a_reply'                          => 'Post a Reply',
            'post_a_note'                           => 'Post a Note',
            'reply_placeholder'                     => 'Add to this conversation...',
            'private_note'                          => 'Private Note',
            'internal_note'                         => 'Internal Note',
            'public_reply'                          => 'Public Reply',
            'post_reply'                            => 'Post Reply',
            'post_note'                             => 'Post Note',
            'ticket_details'                        => 'TICKET DETAILS',
            'contact'                               => 'Contact',
            'assigned'                              => 'ASSIGNED',
            'created'                               => 'CREATED',
            'response'                              => 'RESPONSE',
            'public_ticket'                         => 'PUBLIC TICKET',
            'private'                               => 'Private',
            'public'                                => 'Public',
            'privately_reply'                       => 'Privately Reply',
            'add_customer_note_btn'                 => 'Add Customer Note',
            'no_response_yet'                       => 'No response yet',
            'delete_ticket'                         => 'Delete this ticket',
            'ticket_tags'                           => 'TICKET TAGS',
            'select_some_options'                   => 'Select Some Options',
            'reply_added_successfully'              => 'Reply added successfully!',
            'attachments'                           => 'Attachments',
            'needs_response'                        => 'Needs Response',
            'customer_notes'                        => 'Customer Notes',
            'close_ticket'                          => 'Close Ticket',
            'ticket_closed_successfully'            => 'Ticket closed successfully!',
            'select_tags'                           => 'Select Tags',
            'update_priority_successfully'          => 'Ticket priority updated successfully!',
            'category_updated_successfully'         => 'Ticket category updated successfully!',
            'replied_privately'                     => 'Replied Privately',
            'privacy_policy_note'                   => 'Read our <a href=":privacy_policy_url" target="_blank">Privacy Policy</a> and see how we handle your personal data',
            'all_cleared'                           => 'All cleared.',

            'statuses'   => [
                'pending'    => 'Pending',
                'open'       => 'Open',
                'resolved'   => 'Resolved',
                'closed'     => 'Closed',
                'replied'    => 'Replied (-by customer)',
                'starred'    => 'Starred',
                'unassigned' => 'Unassigned',
            ],
            'priorities' => [
                'low'    => 'Low',
                'medium' => 'Medium',
                'high'   => 'High',
                'urgent' => 'Urgent',
            ],
            'filters'    => [
                'title'               => 'Filters',
                'all_tickets'         => 'All Tickets',
                'my_tickets'          => 'My Tickets',
                'unassigned_tickets'  => 'Unassigned Tickets',
                'starred_tickets'     => 'Starred Tickets',
                'categories'          => 'Categories',
                'no_categories_found' => 'No Categories Found',
                'agents'              => 'Agents',
                'no_agents_found'     => 'No Agents Found',
            ],
            'stats'      => [
                'title'              => 'Quick Statistics',
                'total_tickets'      => 'Total Tickets',
                'open_tickets'       => 'Open Tickets',
                'closed_last_7_days' => 'Closed (Last 7 Days)',
                'closed_older_than'  => 'Older Than 7 Days',
                'closed_other'       => 'Within Last 7 Days (Other)',
                'closed_2days'       => 'Updated 2 Days Ago',
                'updated_today'      => 'Updated Today',
                'no_action_needed'   => 'No Action Needed',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Replies
        |--------------------------------------------------------------------------
        */
        'replies'              => [
            'added_successfully'        => 'Reply added successfully!',
            'message'                   => 'Reply Message',
            'add_reply'                 => 'Add Reply',
            'your_reply'                => 'Your Reply',
            'title_plural'              => 'Replies',
            'edited'                    => 'Edited',
            'note_updated_successfully' => 'Note updated successfully.',
            'fetched_successfully'      => 'Reply fetched successfully.',
            'not_found'                 => 'Reply does not belong to the specified ticket.',
            'updated_successfully'      => 'Reply updated successfully.',
            'deleted_successfully'      => 'Reply deleted successfully.',
            'delete_failed'             => 'Failed to delete the reply.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Attachments
        |--------------------------------------------------------------------------
        */
        'attachments'          => [
            'title_plural'         => 'Attachments',
            'add_attachments'      => 'Add Attachments',
            'not_found'            => 'Attachment does not belong to the specified ticket.',
            'deleted_successfully' => 'Attachment deleted successfully!',
            'delete_failed'        => 'Failed to delete the attachment.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Tags
        |--------------------------------------------------------------------------
        */
        'tags'                 => [
            'title_singular'                => 'Ticket Tag',
            'title_plural'                  => 'Ticket Tags',
            'name'                          => 'Tag Name',
            'name_placeholder'              => 'Enter tag name', // Used "enter_tag_name" before, merging
            'description'                   => 'Tag Description', // General tag description
            'color'                         => 'Color',
            'add_new'                       => 'Add New Tag',
            'edit'                          => 'Edit Tag',
            'created_successfully'          => 'Tag created successfully!',
            'updated_successfully'          => 'Tag updated successfully!',
            'deleted_successfully'          => 'Tag deleted successfully!',
            'deleted_multiple_successfully' => ':count tags deleted successfully!',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Articles
        |--------------------------------------------------------------------------
        */
        'articles'             => [
            'title_singular'             => 'Support Article',
            'title_plural'               => 'Support Articles',
            'create'                     => 'Create Support Article',
            'edit'                       => 'Edit Support Article',
            'published_status'           => 'Published Status',
            'featured_status'            => 'Featured Status',
            'draft'                      => 'Draft',
            'published'                  => 'Published',
            'featured'                   => 'Featured',
            'views'                      => 'Views',
            'confirm_delete_text'        => 'You will not be able to recover this support article!',
            'deleted_successfully'       => 'Support article has been deleted successfully.',
            'delete_failed'              => 'Failed to delete support article.',
            'created_successfully'       => 'Support article created successfully!',
            'updated_successfully'       => 'Support article updated successfully!',
            'update_failed'              => 'Failed to update support article.',
            'published_successfully'     => 'Article published successfully.',
            'unpublished_successfully'   => 'Article unpublished successfully.',
            'featured_successfully'      => 'Article featured successfully.',
            'unfeatured_successfully'    => 'Article unfeatured successfully.',
            'slug_help'                  => 'Leave empty to auto-generate from title. Needs to be unique.',
            'is_published_help'          => 'Leave unchecked to save as draft',
            'confirm_publish'            => 'Are you sure you want to publish the selected articles?',
            'confirm_unpublish'          => 'Are you sure you want to unpublish the selected articles?',
            'confirm_feature'            => 'Are you sure you want to feature the selected articles?',
            'confirm_unfeature'          => 'Are you sure you want to unfeature the selected articles?',
            'confirm_delete'             => 'Are you sure you want to permanently delete the selected articles?',
            'batch_action_success'       => ':count articles have been :action successfully.',
            'batch_action_failed'        => 'Batch action failed.',
            'batch_action_failed_no_ids' => 'Batch action failed. No IDs provided.',
            'help_text'                  => 'Welcome to Support! We have :count Articles to Help You',
            'search_placeholder'         => 'Search our help articles or FAQs...',
            'read_article'               => 'Read Article',
            'featured_articles'          => 'Featured Articles',
            'most_viewed_articles'       => 'Most Viewed Articles',
            'question'                   => 'Question',
            'last_updated_on'            => 'Last Updated On',
        ],


        /*
        |--------------------------------------------------------------------------
        | Batch Actions - General messages for all batch actions
        |--------------------------------------------------------------------------
        */
        'batch_actions'        => [
            'select_action'                         => 'Select Batch Action',
            'select_action_warning'                 => 'Please select a batch action.',
            'select_items_warning'                  => 'Please select at least one item.',
            'confirm_title'                         => 'Are you sure?',
            'confirm_text'                          => 'You are about to perform a batch action: :action on selected items.',
            'you_will_be_able_to_revert_this_later' => 'You will not be able to revert this later.',
            'bulk_actions'                          => 'Bulk Actions',
        ],


        /*
        |--------------------------------------------------------------------------
        | Support Analytics
        |--------------------------------------------------------------------------
        */

        'analytics' => [
            'new_tickets'              => 'New Tickets',
            'agent_responses'          => 'Agent Responses',
            'resolved_tickets'         => 'Resolved Tickets',
            'analytics_overview'       => 'Analytics Overview',
            'all_categories'           => 'All Categories',
            'all_agents'               => 'All Agents',
            'apply_filters'            => 'Apply Filters',
            'no_change_from_yesterday' => 'No Change From Yesterday',
            'from_yesterday'           => 'From Yesterday',
            'agent_replies'            => 'Agent Replies',
            'updated'                  => 'Analytics data updated successfully!',
            'date_range'               => 'Date Range',
            'select_date_range'        => 'Select Date Range',
            'agent'                    => 'Agent',
        ],

        /*
        |--------------------------------------------------------------------------
        | Policy Denial Messages
        |--------------------------------------------------------------------------
        |
        | These messages are returned by policies when authorization fails.
        |
        */
        'policies'  => [
            'support_categories' => [
                'view_any'     => 'You do not have permission to view support categories.',
                'view'         => 'You do not have permission to view this support category.',
                'create'       => 'You do not have permission to create support categories.',
                'update'       => 'You do not have permission to update this support category.',
                'delete'       => 'You do not have permission to delete this support category.',
                'delete_any'   => 'You do not have permission to delete multiple support categories.',
                'batch_action' => 'You do not have permission to perform batch actions on support categories.',
            ],
            'support_articles'   => [
                'view_any'     => 'You do not have permission to view support articles.',
                'view'         => 'You do not have permission to view this support article.',
                'create'       => 'You do not have permission to create support articles.',
                'update'       => 'You do not have permission to update this support article.',
                'delete'       => 'You do not have permission to delete this support article.',
                'delete_any'   => 'You do not have permission to delete multiple support articles.',
                'batch_action' => 'You do not have permission to perform batch actions on support articles.',
                'publish'      => 'You do not have permission to publish or unpublish this support article.',
                'feature'      => 'You do not have permission to feature or unfeature this support article.',
            ],

            'support_tickets'    => [
                'view_any'      => 'You do not have permission to view support tickets.',
                'view'          => 'You do not have permission to view this support ticket.',
                'create'        => 'You do not have permission to create support tickets.',
                'update'        => 'You do not have permission to update this ticket.',
                'delete'        => 'You do not have permission to delete this ticket.',
                'reply'         => 'You do not have permission to reply to this ticket or it is closed.',
                'update_status' => 'You do not have permission to update the status of this ticket.',
                'assign'        => 'You do not have permission to assign this ticket.',
                'manage_tags'   => 'You do not have permission to manage tags for this ticket.',
                'delete_any'    => 'You do not have permission to delete multiple support tickets.',
                'batch_action'  => 'You do not have permission to perform batch actions on support tickets.',
            ],
            'ticket_replies'     => [
                'update'     => 'You do not have permission to update this reply, or the edit window has expired.',
                'delete'     => 'You do not have permission to delete this reply.',
                'delete_any' => 'You do not have permission to delete multiple ticket replies.',
            ],
            'ticket_attachments' => [
                'view'       => 'You do not have permission to view this attachment.',
                'delete'     => 'You do not have permission to delete this attachment.',
                'delete_any' => 'You do not have permission to delete multiple ticket attachments.',
            ],
        ],

    ];
