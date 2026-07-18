<?php

    return [

        // General messages
        'something_went_wrong' => '出现错误。请稍后再试。',
        'permission_denied'    => '您没有执行此操作的权限。',
        'operation_successful' => '操作已成功完成！',

        // Global Labels
        'last_modified'        => '最后修改',
        'description'          => '描述',
        'slug'                 => '别名',
        'is_active'            => '是否启用？',
        'add_new'              => '新增',
        'edit'                 => '编辑',
        'back_to_list'         => '返回列表',
        'select_category'      => '-- 选择分类 --',
        'uncategorized'        => '未分类',
        'unknown_user'         => '未知用户',
        'confirm_delete_title' => '您确定吗？',
        'you'                  => '您',
        'updated'              => '已更新',


        // Buttons
        'buttons'              => [
            'open'       => '打开',
            'reply'      => '回复',
            'publish'    => '发布',
            'unpublish'  => '取消发布',
            'feature'    => '推荐',
            'unfeature'  => '取消推荐',
            'deactivate' => '停用',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Settings
        |--------------------------------------------------------------------------
        */
        'settings'             => [
            'support_desk_name'               => '客服中心名称',
            'support_desk_name_help'          => '用于您的支持系统、邮件等。',
            'support_desk_email'              => '客服中心邮箱',
            'support_desk_email_help'         => '仅用于发送重要更新。',
            'title'                           => '支持设置',
            'ticket_settings'                 => '工单设置',
            'ticket_tags'                     => '工单标签',
            'privacy_settings'                => '隐私设置',
            'important_notice_messages'       => '重要通知消息',
            'auto_reassign'                   => '自动将工单重新分配给回复的支持人员',
            'filter_by_category'              => '在工单回复框内按分类筛选文章列表',
            'disable_public_tickets'          => '禁用公开工单 <span class="text-muted small">(当前的公开工单不受影响)</span>',
            'public_tickets_default'          => '公开工单为默认（而不是私有）',
            'order_by_last_updated'           => '按最后更新时间升序排列工单',
            'group_by_last_updated'           => '按最后更新时间分组工单',
            'enable_autoresponder'            => '启用自动回复消息',
            'enable_important_notice_message' => '启用重要通知消息',
            'updated_successfully'            => '设置更新成功。',
            'ticket_tag_updated_successfully' => '工单标签更新成功。',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Agents
        |--------------------------------------------------------------------------
        */
        'agents'               => [
            'open_tickets'                       => '未处理工单',
            'closed_tickets'                     => '已关闭工单',
            'last_signed_in'                     => '上次登录',
            'statistics'                         => '统计',
            'assign_agent'                       => '分配客服',
            'edit_agent'                         => '编辑客服',
            'designation'                        => '职位',
            'bio'                                => '简介',
            'description'                        => '首先，在 <a href=":role_url" target="_blank">创建角色</a>，然后在 <a href=":administrator_url" target="_blank">创建管理员</a>。将该角色分配给管理员，最后将该管理员指定为客服人员。',
            'assigned_successfully'              => '客服分配成功。',
            'already_assigned_or_no_change'      => '客服已分配或未发生更改。',
            'never_logged_in'                    => '从未登录',
            'confirm_activate_text'              => '您确定要激活这些客服吗？',
            'confirm_deactivate_text'            => '您确定要停用这些客服吗？',
            'confirm_delete_text'                => '您确定要将这些用户从客服中释放吗？',
            'yes_activate_it'                    => '是的，激活！',
            'yes_deactivate_it'                  => '是的，停用！',
            'yes_release_it'                     => '是的，释放！',
            'deactivated_successfully'           => '客服停用成功。',
            'updated_successfully'               => '客服更新成功。',
            'deleted_successfully'               => '管理员用户已从客服中释放。',
            'no_valid_selected_for_deactivation' => '未选择有效的客服进行停用。',
            'activate_selected'                  => '激活所选',
            'activated_multiple_successfully'    => '已成功激活 :count 名客服。',
            'deactivate_selected'                => '停用所选',
            'deactivated_multiple_successfully'  => '已成功停用 :count 名客服。',
            'delete_selected'                    => '释放所选',
            'deleted_multiple_successfully'      => '已成功释放 :count 名客服。',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Categories
        |--------------------------------------------------------------------------
        */
        'categories'           => [
            'title_singular'                => '支持分类',
            'title_plural'                  => '支持分类',
            'name'                          => '分类名称',
            'icon'                          => '图标',
            'name_placeholder'              => '例如：技术问题，账单咨询',
            'slug_placeholder'              => '例如：technical-issues',
            'description_placeholder'       => '简要描述该分类',
            'add_new'                       => '新增分类',
            'edit'                          => '编辑分类',
            'details'                       => '分类详情',
            'select_icon'                   => '选择图标',
            'search_icons'                  => '搜索图标...',
            'created_successfully'          => '支持分类创建成功！',
            'updated_successfully'          => '支持分类更新成功！',
            'deleted_successfully'          => '支持分类删除成功！',
            'confirm_activate'              => '您确定要激活所选分类吗？',
            'confirm_deactivate'            => '您确定要停用所选分类吗？',
            'confirm_delete'                => '您确定要永久删除所选分类吗？',
            'activated_successfully'        => '已成功激活 :count 个分类。',
            'deactivated_successfully'      => '已成功停用 :count 个分类。',
            'deleted_multiple_successfully' => '已成功删除 :count 个分类。',
            'slug_auto_generated_help'      => '如果留空，将根据名称自动生成别名。',
            'browse_by_category'            => '按分类浏览',
            'published_on'                  => '发布时间',
            'related_questions'             => '相关问题',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Tickets
        |--------------------------------------------------------------------------
        */
        'tickets'              => [
            'title_singular'                        => '支持工单',
            'title_plural'                          => '支持工单',
            'create_ticket'                         => '创建工单',
            'view_ticket'                           => '查看工单',
            'created_successfully'                  => '工单创建成功！',
            'updated_successfully'                  => '工单更新成功！',
            'deleted_successfully'                  => '工单删除成功！',
            'status_updated_successfully'           => '工单状态更新成功！',
            'assigned_successfully'                 => '工单分配成功！',
            'tags_updated_successfully'             => '工单标签更新成功！',
            'confirm_action'                        => '您即将 :action :length 个工单。',
            'opened_successfully'                   => ':count 个工单成功打开！',
            'closed_successfully'                   => ':count 个工单成功关闭！',
            'marked_pending_successfully'           => ':count 个工单成功标记为待处理！',
            'assigned_multiple_successfully'        => ':count 个工单成功分配给 :agent！',
            'deleted_multiple_successfully'         => ':count 个工单成功删除！',
            'starred_successfully'                  => '工单星标状态更新成功！',
            'category'                              => '分类',
            'assigned_agent'                        => '分配客服',
            'last_replied'                          => '最后回复',
            'priority'                              => '优先级',
            'customer_name'                         => '客户姓名',
            'customer_email'                        => '客户邮箱',
            'please_select_an_agent_for_assignment' => '请选择客服进行分配。',
            'search_placeholder'                    => '搜索工单...',
            'filter_by_status'                      => '按状态筛选',
            'filter_by_priority'                    => '按优先级筛选',
            'assigned_to'                           => '分配给 :agent_name',
            'unassigned'                            => '未分配',
            'last_updated'                          => ':time 前更新',
            'no_tickets_found'                      => '未找到符合条件的工单。',
            'category_help'                         => '您需要哪类帮助？',
            'subject_help'                          => '总体来说，这个工单是关于什么的？',
            'subject_placeholder'                   => '工单的主题是什么？',
            'related_url'                           => '相关链接',
            'related_url_help'                      => '可选，但非常有帮助。',
            'description_help'                      => '请尽可能详细描述此工单的内容。',
            'description_placeholder'               => '我们今天能为您做些什么？',
            'agent_help'                            => '可选，将工单分配给客服。',
            'select_agent'                          => '选择客服',
            'file_type_not_allowed'                 => '不允许的文件类型。',
            'file_extension_not_allowed'            => '不允许的文件扩展名。',
            'file_too_large'                        => '文件过大。最大允许 :max_size。',
            'one_or_more_files_invalid'             => '一个或多个文件无效（类型或大小）。请检查后重试。',
            'max_attachments_exceeded'              => '最多可上传 :max 个附件。',
            'make_public'                           => '将此工单设为公开',
            'is_public_customer_notes'              => '默认情况下，只有支持团队可以查看和回复您的工单。',
            'is_public_help'                        => '<i>公开工单</i> 允许整个社区查看和回复。<b>但他们无法查看填写在“私有”字段中的信息。</b>',
            'status_help'                           => '工单的当前状态。',
            'select_status'                         => '选择状态',
            'priority_help'                         => '工单的重要程度。',
            'select_priority'                       => '选择优先级',
            'customer_help'                         => '选择为哪个客户创建工单。',
            'select_customer'                       => '选择客户',
            'private_ticket'                        => '私有工单',
            'original_description'                  => '原始工单描述',
            'no_replies_yet'                        => '暂无回复或备注。',
            'post_a_reply'                          => '发表回复',
            'post_a_note'                           => '添加备注',
            'reply_placeholder'                     => '添加到此对话...',
            'private_note'                          => '私人备注',
            'internal_note'                         => '内部备注',
            'public_reply'                          => '公开回复',
            'post_reply'                            => '提交回复',
            'post_note'                             => '提交备注',
            'ticket_details'                        => '工单详情',
            'contact'                               => '联系人',
            'assigned'                              => '已分配',
            'created'                               => '已创建',
            'response'                              => '回复',
            'public_ticket'                         => '公开工单',
            'private'                               => '私有',
            'public'                                => '公开',
            'privately_reply'                       => '私下回复',
            'add_customer_note_btn'                 => '添加客户备注',
            'no_response_yet'                       => '暂无回复',
            'delete_ticket'                         => '删除工单',
            'ticket_tags'                           => '工单标签',
            'select_some_options'                   => '选择一些选项',
            'reply_added_successfully'              => '回复添加成功！',
            'attachments'                           => '附件',
            'needs_response'                        => '需要回复',
            'customer_notes'                        => '客户备注',
            'close_ticket'                          => '关闭工单',
            'ticket_closed_successfully'            => '工单关闭成功！',
            'select_tags'                           => '选择标签',
            'update_priority_successfully'          => '工单优先级更新成功！',
            'category_updated_successfully'         => '工单分类更新成功！',
            'replied_privately'                     => '已私下回复',
            'privacy_policy_note'                   => '阅读我们的 <a href=":privacy_policy_url" target="_blank">隐私政策</a> 了解我们如何处理您的个人数据',
            'all_cleared'                           => '全部清除。',

            'statuses'   => [
                'pending'    => '待处理',
                'open'       => '打开',
                'resolved'   => '已解决',
                'closed'     => '已关闭',
                'replied'    => '已回复（客户）',
                'starred'    => '已加星',
                'unassigned' => '未分配',
            ],
            'priorities' => [
                'low'    => '低',
                'medium' => '中',
                'high'   => '高',
                'urgent' => '紧急',
            ],
            'filters'    => [
                'title'               => '筛选器',
                'all_tickets'         => '所有工单',
                'my_tickets'          => '我的工单',
                'unassigned_tickets'  => '未分配工单',
                'starred_tickets'     => '星标工单',
                'categories'          => '分类',
                'no_categories_found' => '未找到分类',
                'agents'              => '客服',
                'no_agents_found'     => '未找到客服',
            ],
            'stats'      => [
                'title'              => '快速统计',
                'total_tickets'      => '工单总数',
                'open_tickets'       => '未处理工单',
                'closed_last_7_days' => '7天内已关闭',
                'closed_older_than'  => '超过7天已关闭',
                'closed_other'       => '7天内关闭（其他）',
                'closed_2days'       => '2天前更新',
                'updated_today'      => '今日更新',
                'no_action_needed'   => '无需操作',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Replies
        |--------------------------------------------------------------------------
        */
        'replies'              => [
            'added_successfully'        => '回复添加成功！',
            'message'                   => '回复内容',
            'add_reply'                 => '添加回复',
            'your_reply'                => '您的回复',
            'title_plural'              => '回复',
            'edited'                    => '已编辑',
            'note_updated_successfully' => '备注更新成功。',
            'fetched_successfully'      => '回复获取成功。',
            'not_found'                 => '回复不属于指定工单。',
            'updated_successfully'      => '回复更新成功。',
            'deleted_successfully'      => '回复删除成功。',
            'delete_failed'             => '删除回复失败。',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Attachments
        |--------------------------------------------------------------------------
        */
        'attachments'          => [
            'title_plural'         => '附件',
            'add_attachments'      => '添加附件',
            'not_found'            => '附件不属于指定工单。',
            'deleted_successfully' => '附件删除成功！',
            'delete_failed'        => '删除附件失败。',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Tags
        |--------------------------------------------------------------------------
        */
        'tags'                 => [
            'title_singular'                => '工单标签',
            'title_plural'                  => '工单标签',
            'name'                          => '标签名称',
            'name_placeholder'              => '输入标签名称',
            'description'                   => '标签描述',
            'color'                         => '颜色',
            'add_new'                       => '新增标签',
            'edit'                          => '编辑标签',
            'created_successfully'          => '标签创建成功！',
            'updated_successfully'          => '标签更新成功！',
            'deleted_successfully'          => '标签删除成功！',
            'deleted_multiple_successfully' => '已成功删除 :count 个标签！',
        ],
        /*
        |--------------------------------------------------------------------------
        | Support Articles
        |--------------------------------------------------------------------------
        */
        'articles'             => [
            'title_singular'             => '支持文章',
            'title_plural'               => '支持文章',
            'create'                     => '创建支持文章',
            'edit'                       => '编辑支持文章',
            'published_status'           => '发布状态',
            'featured_status'            => '精选状态',
            'draft'                      => '草稿',
            'published'                  => '已发布',
            'featured'                   => '已精选',
            'views'                      => '浏览量',
            'confirm_delete_text'        => '您将无法恢复此支持文章！',
            'deleted_successfully'       => '支持文章已成功删除。',
            'delete_failed'              => '删除支持文章失败。',
            'created_successfully'       => '支持文章已成功创建！',
            'updated_successfully'       => '支持文章已成功更新！',
            'update_failed'              => '更新支持文章失败。',
            'published_successfully'     => '文章已成功发布。',
            'unpublished_successfully'   => '文章已成功取消发布。',
            'featured_successfully'      => '文章已成功设为精选。',
            'unfeatured_successfully'    => '文章已成功取消精选。',
            'slug_help'                  => '留空以根据标题自动生成。必须唯一。',
            'is_published_help'          => '未勾选则保存为草稿',
            'confirm_publish'            => '您确定要发布所选文章吗？',
            'confirm_unpublish'          => '您确定要取消发布所选文章吗？',
            'confirm_feature'            => '您确定要将所选文章设为精选吗？',
            'confirm_unfeature'          => '您确定要取消所选文章的精选吗？',
            'confirm_delete'             => '您确定要永久删除所选文章吗？',
            'batch_action_success'       => '已成功:action :count 篇文章。',
            'batch_action_failed'        => '批量操作失败。',
            'batch_action_failed_no_ids' => '批量操作失败。未提供 ID。',
            'help_text'                  => '欢迎来到支持！我们有 :count 篇文章来帮助您',
            'search_placeholder'         => '搜索我们的帮助文章或常见问题...',
            'read_article'               => '阅读文章',
            'featured_articles'          => '精选文章',
            'most_viewed_articles'       => '最受欢迎的文章',
            'question'                   => '问题',
        ],


        /*
        |--------------------------------------------------------------------------
        | Batch Actions - General messages for all batch actions
        |--------------------------------------------------------------------------
        */
        'batch_actions'        => [
            'select_action'                         => '选择批量操作',
            'select_action_warning'                 => '请选择一个批量操作。',
            'select_items_warning'                  => '请至少选择一个项目。',
            'confirm_title'                         => '您确定吗？',
            'confirm_text'                          => '您即将对所选项目执行批量操作：:action。',
            'you_will_be_able_to_revert_this_later' => '您将无法在之后撤销此操作。',
            'bulk_actions'                          => '批量操作',
        ],


        /*
        |--------------------------------------------------------------------------
        | Support Analytics
        |--------------------------------------------------------------------------
        */
        'analytics'            => [
            'new_tickets'              => '新工单',
            'agent_responses'          => '客服回复',
            'resolved_tickets'         => '已解决工单',
            'analytics_overview'       => '分析概览',
            'all_categories'           => '所有分类',
            'all_agents'               => '所有客服',
            'apply_filters'            => '应用筛选',
            'no_change_from_yesterday' => '与昨天相比无变化',
            'from_yesterday'           => '来自昨天的数据',
            'agent_replies'            => '客服回复数',
            'updated'                  => '分析数据已成功更新！',
            'date_range'               => '日期范围',
            'select_date_range'        => '选择日期范围',
            'agent'                    => '客服',
        ],


        /*
        |--------------------------------------------------------------------------
        | Policy Denial Messages
        |--------------------------------------------------------------------------
        |
        | These messages are returned by policies when authorization fails.
        |
        */
        'policies'             => [
            'support_categories' => [
                'view_any'     => '您没有权限查看支持分类。',
                'view'         => '您没有权限查看此支持分类。',
                'create'       => '您没有权限创建支持分类。',
                'update'       => '您没有权限更新此支持分类。',
                'delete'       => '您没有权限删除此支持分类。',
                'delete_any'   => '您没有权限删除多个支持分类。',
                'batch_action' => '您没有权限对支持分类执行批量操作。',
            ],
            'support_articles'   => [
                'view_any'     => '您没有权限查看支持文章。',
                'view'         => '您没有权限查看此支持文章。',
                'create'       => '您没有权限创建支持文章。',
                'update'       => '您没有权限更新此支持文章。',
                'delete'       => '您没有权限删除此支持文章。',
                'delete_any'   => '您没有权限删除多篇支持文章。',
                'batch_action' => '您没有权限对支持文章执行批量操作。',
                'publish'      => '您没有权限发布或取消发布此支持文章。',
                'feature'      => '您没有权限将此支持文章设为精选或取消精选。',
            ],

            'support_tickets'    => [
                'view_any'      => '您没有权限查看支持工单。',
                'view'          => '您没有权限查看此支持工单。',
                'create'        => '您没有权限创建支持工单。',
                'update'        => '您没有权限更新此工单。',
                'delete'        => '您没有权限删除此工单。',
                'reply'         => '您没有权限回复此工单或该工单已关闭。',
                'update_status' => '您没有权限更新此工单的状态。',
                'assign'        => '您没有权限分配此工单。',
                'manage_tags'   => '您没有权限管理此工单的标签。',
                'delete_any'    => '您没有权限删除多个支持工单。',
                'batch_action'  => '您没有权限对支持工单执行批量操作。',
            ],
            'ticket_replies'     => [
                'update'     => '您没有权限更新此回复，或编辑时间已过。',
                'delete'     => '您没有权限删除此回复。',
                'delete_any' => '您没有权限删除多个工单回复。',
            ],
            'ticket_attachments' => [
                'view'       => '您没有权限查看此附件。',
                'delete'     => '您没有权限删除此附件。',
                'delete_any' => '您没有权限删除多个工单附件。',
            ],
        ],

    ];
