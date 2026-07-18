<?php

    return [

        // General messages
        'something_went_wrong' => '문제가 발생했습니다. 나중에 다시 시도해주세요.',
        'permission_denied'    => '이 작업을 수행할 권한이 없습니다.',
        'operation_successful' => '작업이 성공적으로 완료되었습니다!',

        // Global Labels
        'last_modified'        => '마지막 수정일',
        'description'          => '설명',
        'slug'                 => '슬러그',
        'is_active'            => '활성 상태인가요?',
        'add_new'              => '새로 추가',
        'edit'                 => '편집',
        'back_to_list'         => '목록으로 돌아가기',
        'select_category'      => '-- 카테고리 선택 --',
        'uncategorized'        => '분류되지 않음',
        'unknown_user'         => '알 수 없는 사용자',
        'confirm_delete_title' => '정말로 삭제하시겠습니까?',
        'you'                  => '사용자',
        'updated'              => '업데이트됨',

        // Buttons
        'buttons'              => [
            'open'       => '열기',
            'reply'      => '답장',
            'publish'    => '게시',
            'unpublish'  => '게시 취소',
            'feature'    => '추천',
            'unfeature'  => '추천 취소',
            'deactivate' => '비활성화',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Settings
        |--------------------------------------------------------------------------
        */
        'settings'             => [
            'support_desk_name'               => '지원 데스크 이름',
            'support_desk_name_help'          => '이름은 전체 지원 시스템, 이메일 등에서 사용됩니다.',
            'support_desk_email'              => '지원 데스크 이메일',
            'support_desk_email_help'         => '중요 알림 전송에만 사용됩니다.',
            'title'                           => '지원 설정',
            'ticket_settings'                 => '티켓 설정',
            'ticket_tags'                     => '티켓 태그',
            'privacy_settings'                => '개인정보 설정',
            'important_notice_messages'       => '중요 공지 메시지',
            'auto_reassign'                   => '응답한 지원 담당자에게 티켓 자동 재할당',
            'filter_by_category'              => '티켓 답변 상자 내 카테고리별 기사 필터링',
            'disable_public_tickets'          => '공개 티켓 비활성화 <span class="text-muted small">(현재 공개 티켓에는 영향 없음)</span>',
            'public_tickets_default'          => '공개 티켓이 기본값입니다 (비공개 대신)',
            'order_by_last_updated'           => '마지막 업데이트 날짜 기준 오름차순 정렬',
            'group_by_last_updated'           => '마지막 업데이트 날짜 기준 그룹화',
            'enable_autoresponder'            => '자동응답 메시지 활성화',
            'enable_important_notice_message' => '중요 공지 메시지 활성화',
            'updated_successfully'            => '설정이 성공적으로 업데이트되었습니다.',
            'ticket_tag_updated_successfully' => '티켓 태그가 성공적으로 업데이트되었습니다.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Agents
        |--------------------------------------------------------------------------
        */
        'agents'               => [
            'open_tickets'                       => '열린 티켓',
            'closed_tickets'                     => '닫힌 티켓',
            'last_signed_in'                     => '마지막 로그인',
            'statistics'                         => '통계',
            'assign_agent'                       => '담당자 지정',
            'edit_agent'                         => '담당자 편집',
            'designation'                        => '직책',
            'bio'                                => '소개',
            'description'                        => '<a href=":role_url" target="_blank">역할 생성</a> 후 <a href=":administrator_url" target="_blank">관리자 생성</a>에서 관리자 생성. 생성된 역할을 관리자에게 할당한 후 지원 담당자로 지정하세요.',
            'assigned_successfully'              => '담당자가 성공적으로 지정되었습니다.',
            'already_assigned_or_no_change'      => '이미 지정되었거나 변경 사항이 없습니다.',
            'never_logged_in'                    => '로그인 기록 없음',
            'confirm_activate_text'              => '이 담당자를 활성화하시겠습니까?',
            'confirm_deactivate_text'            => '이 담당자를 비활성화하시겠습니까?',
            'confirm_delete_text'                => '이 담당자를 지원 담당자에서 해제하시겠습니까?',
            'yes_activate_it'                    => '예, 활성화합니다!',
            'yes_deactivate_it'                  => '예, 비활성화합니다!',
            'yes_release_it'                     => '예, 해제합니다!',
            'deactivated_successfully'           => '담당자가 성공적으로 비활성화되었습니다.',
            'updated_successfully'               => '담당자가 성공적으로 업데이트되었습니다.',
            'deleted_successfully'               => '관리자가 지원 담당자에서 성공적으로 해제되었습니다.',
            'no_valid_selected_for_deactivation' => '비활성화할 유효한 담당자가 선택되지 않았습니다.',
            'activate_selected'                  => '선택 항목 활성화',
            'activated_multiple_successfully'    => ':count명의 담당자가 성공적으로 활성화되었습니다.',
            'deactivate_selected'                => '선택 항목 비활성화',
            'deactivated_multiple_successfully'  => ':count명의 담당자가 성공적으로 비활성화되었습니다.',
            'delete_selected'                    => '선택 항목 해제',
            'deleted_multiple_successfully'      => ':count명의 담당자가 지원 담당자에서 해제되었습니다.',
        ],


        /*
|--------------------------------------------------------------------------
| Support Categories
|--------------------------------------------------------------------------
*/
        'categories'           => [
            'title_singular'                => '지원 카테고리',
            'title_plural'                  => '지원 카테고리',
            'name'                          => '카테고리 이름',
            'icon'                          => '아이콘',
            'name_placeholder'              => '예: 기술 문제, 결제 문의',
            'slug_placeholder'              => '예: technical-issues',
            'description_placeholder'       => '카테고리를 간단히 설명하세요',
            'add_new'                       => '새 카테고리 추가',
            'edit'                          => '카테고리 수정',
            'details'                       => '카테고리 상세',
            'select_icon'                   => '아이콘 선택',
            'search_icons'                  => '아이콘 검색...',
            'created_successfully'          => '지원 카테고리가 성공적으로 생성되었습니다!',
            'updated_successfully'          => '지원 카테고리가 성공적으로 업데이트되었습니다!',
            'deleted_successfully'          => '지원 카테고리가 성공적으로 삭제되었습니다!',
            'confirm_activate'              => '선택한 카테고리를 활성화하시겠습니까?',
            'confirm_deactivate'            => '선택한 카테고리를 비활성화하시겠습니까?',
            'confirm_delete'                => '선택한 카테고리를 영구 삭제하시겠습니까?',
            'activated_successfully'        => ':count개의 카테고리가 성공적으로 활성화되었습니다.',
            'deactivated_successfully'      => ':count개의 카테고리가 성공적으로 비활성화되었습니다.',
            'deleted_multiple_successfully' => ':count개의 카테고리가 성공적으로 삭제되었습니다.',
            'slug_auto_generated_help'      => '빈칸으로 두면 이름에서 자동으로 슬러그가 생성됩니다.',
            'browse_by_category'            => '카테고리별 보기',
            'published_on'                  => '게시일',
            'related_questions'             => '관련 질문',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Tickets
        |--------------------------------------------------------------------------
        */
        'tickets'              => [
            'title_singular'                        => '지원 티켓',
            'title_plural'                          => '지원 티켓',
            'create_ticket'                         => '티켓 생성',
            'view_ticket'                           => '티켓 보기',
            'created_successfully'                  => '지원 티켓이 성공적으로 생성되었습니다!',
            'updated_successfully'                  => '지원 티켓이 성공적으로 업데이트되었습니다!',
            'deleted_successfully'                  => '지원 티켓이 성공적으로 삭제되었습니다!',
            'status_updated_successfully'           => '티켓 상태가 성공적으로 업데이트되었습니다!',
            'assigned_successfully'                 => '티켓이 성공적으로 할당되었습니다!',
            'tags_updated_successfully'             => '티켓 태그가 성공적으로 업데이트되었습니다!',
            'confirm_action'                        => ':length개의 티켓에 :action 작업을 수행하시겠습니까?',
            'opened_successfully'                   => ':count개의 티켓이 성공적으로 열렸습니다!',
            'closed_successfully'                   => ':count개의 티켓이 성공적으로 닫혔습니다!',
            'marked_pending_successfully'           => ':count개의 티켓이 보류 상태로 표시되었습니다!',
            'assigned_multiple_successfully'        => ':count개의 티켓이 :agent에게 성공적으로 할당되었습니다!',
            'deleted_multiple_successfully'         => ':count개의 티켓이 성공적으로 삭제되었습니다!',
            'starred_successfully'                  => '티켓 즐겨찾기 상태가 성공적으로 업데이트되었습니다!',
            'category'                              => '카테고리',
            'assigned_agent'                        => '할당된 담당자',
            'last_replied'                          => '마지막 답변',
            'priority'                              => '우선순위',
            'customer_name'                         => '고객 이름',
            'customer_email'                        => '고객 이메일',
            'please_select_an_agent_for_assignment' => '할당할 담당자를 선택해주세요.',
            'search_placeholder'                    => '티켓 검색...',
            'filter_by_status'                      => '상태별 필터',
            'filter_by_priority'                    => '우선순위별 필터',
            'assigned_to'                           => ':agent_name에게 할당',
            'unassigned'                            => '미할당',
            'last_updated'                          => ':time 전에 업데이트됨',
            'no_tickets_found'                      => '조건에 맞는 티켓이 없습니다.',
            'category_help'                         => '어떤 카테고리에 도움이 필요하신가요?',
            'subject_help'                          => '일반적으로 이 티켓은 무엇에 관한 것인가요?',
            'subject_placeholder'                   => '이 티켓은 무엇에 관한 것인가요?',
            'related_url'                           => '관련 URL',
            'related_url_help'                      => '선택 사항이지만 매우 유용합니다.',
            'description_help'                      => '이 티켓의 세부 사항을 최대한 자세히 설명해주세요.',
            'description_placeholder'               => '오늘 무엇을 도와드릴까요?',
            'agent_help'                            => '선택적으로 담당자를 이 티켓에 할당할 수 있습니다.',
            'select_agent'                          => '담당자 선택',
            'file_type_not_allowed'                 => '허용되지 않는 파일 형식입니다.',
            'file_extension_not_allowed'            => '허용되지 않는 파일 확장자입니다.',
            'file_too_large'                        => '파일이 너무 큽니다. 최대 :max_size까지 허용됩니다.',
            'one_or_more_files_invalid'             => '선택한 파일 중 하나 이상이 유효하지 않습니다 (형식 또는 크기). 확인 후 다시 시도하세요.',
            'max_attachments_exceeded'              => '최대 :max개의 첨부파일만 업로드할 수 있습니다.',
            'make_public'                           => '이 티켓을 공개로 전환',
            'is_public_customer_notes'              => '기본적으로 지원팀만 티켓을 보고 응답할 수 있습니다.',
            'is_public_help'                        => '<i>공개 티켓</i>은 전체 커뮤니티가 보고 응답할 수 있습니다. <b>단, 위의 "비공개" 필드에 입력된 정보는 볼 수 없습니다.</b>',
            'status_help'                           => '티켓의 현재 상태입니다.',
            'select_status'                         => '상태 선택',
            'priority_help'                         => '티켓의 중요도 수준입니다.',
            'select_priority'                       => '우선순위 선택',
            'customer_help'                         => '이 티켓이 생성되는 고객을 선택하세요.',
            'select_customer'                       => '고객 선택',
            'private_ticket'                        => '비공개 티켓',
            'original_description'                  => '원본 티켓 설명',
            'no_replies_yet'                        => '아직 답변이나 노트가 추가되지 않았습니다.',
            'post_a_reply'                          => '답변 작성',
            'post_a_note'                           => '노트 작성',
            'reply_placeholder'                     => '대화에 추가...',
            'private_note'                          => '비공개 노트',
            'internal_note'                         => '내부 노트',
            'public_reply'                          => '공개 답변',
            'post_reply'                            => '답변 게시',
            'post_note'                             => '노트 게시',
            'ticket_details'                        => '티켓 상세',
            'contact'                               => '연락처',
            'assigned'                              => '할당됨',
            'created'                               => '생성됨',
            'response'                              => '응답',
            'public_ticket'                         => '공개 티켓',
            'private'                               => '비공개',
            'public'                                => '공개',
            'privately_reply'                       => '비공개 답변',
            'add_customer_note_btn'                 => '고객 노트 추가',
            'no_response_yet'                       => '아직 응답 없음',
            'delete_ticket'                         => '이 티켓 삭제',
            'ticket_tags'                           => '티켓 태그',
            'select_some_options'                   => '옵션 선택',
            'reply_added_successfully'              => '답변이 성공적으로 추가되었습니다!',
            'attachments'                           => '첨부파일',
            'needs_response'                        => '응답 필요',
            'customer_notes'                        => '고객 노트',
            'close_ticket'                          => '티켓 닫기',
            'ticket_closed_successfully'            => '티켓이 성공적으로 닫혔습니다!',
            'select_tags'                           => '태그 선택',
            'update_priority_successfully'          => '티켓 우선순위가 성공적으로 업데이트되었습니다!',
            'category_updated_successfully'         => '티켓 카테고리가 성공적으로 업데이트되었습니다!',
            'replied_privately'                     => '비공개로 답변함',
            'privacy_policy_note'                   => '<a href=":privacy_policy_url" target="_blank">개인정보 처리방침</a>을 읽고, 개인 데이터 처리 방법을 확인하세요.',
            'all_cleared'                           => '모두 초기화되었습니다.',

            'statuses'   => [
                'pending'    => '보류',
                'open'       => '열림',
                'resolved'   => '해결됨',
                'closed'     => '닫힘',
                'replied'    => '답변됨 (고객)',
                'starred'    => '즐겨찾기',
                'unassigned' => '미할당',
            ],
            'priorities' => [
                'low'    => '낮음',
                'medium' => '중간',
                'high'   => '높음',
                'urgent' => '긴급',
            ],
            'filters'    => [
                'title'               => '필터',
                'all_tickets'         => '모든 티켓',
                'my_tickets'          => '내 티켓',
                'unassigned_tickets'  => '미할당 티켓',
                'starred_tickets'     => '즐겨찾기 티켓',
                'categories'          => '카테고리',
                'no_categories_found' => '카테고리가 없습니다',
                'agents'              => '담당자',
                'no_agents_found'     => '담당자가 없습니다',
            ],
            'stats'      => [
                'title'              => '빠른 통계',
                'total_tickets'      => '총 티켓',
                'open_tickets'       => '열린 티켓',
                'closed_last_7_days' => '최근 7일 닫힘',
                'closed_older_than'  => '7일 이상 이전',
                'closed_other'       => '최근 7일 내 기타',
                'closed_2days'       => '2일 전에 업데이트됨',
                'updated_today'      => '오늘 업데이트됨',
                'no_action_needed'   => '조치 필요 없음',
            ],
        ],


        /*
|--------------------------------------------------------------------------
| Ticket Replies
|--------------------------------------------------------------------------
*/
        'replies'              => [
            'added_successfully'        => '답변이 성공적으로 추가되었습니다!',
            'message'                   => '답변 메시지',
            'add_reply'                 => '답변 추가',
            'your_reply'                => '당신의 답변',
            'title_plural'              => '답변들',
            'edited'                    => '수정됨',
            'note_updated_successfully' => '노트가 성공적으로 업데이트되었습니다.',
            'fetched_successfully'      => '답변이 성공적으로 가져와졌습니다.',
            'not_found'                 => '이 답변은 지정된 티켓에 속하지 않습니다.',
            'updated_successfully'      => '답변이 성공적으로 업데이트되었습니다.',
            'deleted_successfully'      => '답변이 성공적으로 삭제되었습니다.',
            'delete_failed'             => '답변 삭제에 실패했습니다.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Attachments
        |--------------------------------------------------------------------------
        */
        'attachments'          => [
            'title_plural'         => '첨부파일',
            'add_attachments'      => '첨부파일 추가',
            'not_found'            => '이 첨부파일은 지정된 티켓에 속하지 않습니다.',
            'deleted_successfully' => '첨부파일이 성공적으로 삭제되었습니다!',
            'delete_failed'        => '첨부파일 삭제에 실패했습니다.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Tags
        |--------------------------------------------------------------------------
        */
        'tags'                 => [
            'title_singular'                => '티켓 태그',
            'title_plural'                  => '티켓 태그들',
            'name'                          => '태그 이름',
            'name_placeholder'              => '태그 이름 입력',
            'description'                   => '태그 설명',
            'color'                         => '색상',
            'add_new'                       => '새 태그 추가',
            'edit'                          => '태그 수정',
            'created_successfully'          => '태그가 성공적으로 생성되었습니다!',
            'updated_successfully'          => '태그가 성공적으로 업데이트되었습니다!',
            'deleted_successfully'          => '태그가 성공적으로 삭제되었습니다!',
            'deleted_multiple_successfully' => ':count개의 태그가 성공적으로 삭제되었습니다!',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Articles
        |--------------------------------------------------------------------------
        */
        'articles'             => [
            'title_singular'             => '지원 문서',
            'title_plural'               => '지원 문서들',
            'create'                     => '지원 문서 생성',
            'edit'                       => '지원 문서 수정',
            'published_status'           => '게시 상태',
            'featured_status'            => '추천 상태',
            'draft'                      => '임시 저장',
            'published'                  => '게시됨',
            'featured'                   => '추천됨',
            'views'                      => '조회수',
            'confirm_delete_text'        => '이 지원 문서는 복구할 수 없습니다!',
            'deleted_successfully'       => '지원 문서가 성공적으로 삭제되었습니다.',
            'delete_failed'              => '지원 문서 삭제에 실패했습니다.',
            'created_successfully'       => '지원 문서가 성공적으로 생성되었습니다!',
            'updated_successfully'       => '지원 문서가 성공적으로 업데이트되었습니다!',
            'update_failed'              => '지원 문서 업데이트에 실패했습니다.',
            'published_successfully'     => '문서가 성공적으로 게시되었습니다.',
            'unpublished_successfully'   => '문서 게시가 취소되었습니다.',
            'featured_successfully'      => '문서가 성공적으로 추천되었습니다.',
            'unfeatured_successfully'    => '문서 추천이 취소되었습니다.',
            'slug_help'                  => '비워두면 제목에서 자동 생성됩니다. 고유해야 합니다.',
            'is_published_help'          => '체크 해제 시 임시 저장으로 저장됩니다.',
            'confirm_publish'            => '선택한 문서를 게시하시겠습니까?',
            'confirm_unpublish'          => '선택한 문서 게시를 취소하시겠습니까?',
            'confirm_feature'            => '선택한 문서를 추천하시겠습니까?',
            'confirm_unfeature'          => '선택한 문서 추천을 취소하시겠습니까?',
            'confirm_delete'             => '선택한 문서를 영구 삭제하시겠습니까?',
            'batch_action_success'       => ':count개의 문서가 성공적으로 :action 되었습니다.',
            'batch_action_failed'        => '배치 작업 실패',
            'batch_action_failed_no_ids' => '배치 작업 실패. ID가 제공되지 않았습니다.',
            'help_text'                  => '지원에 오신 것을 환영합니다! :count개의 문서를 통해 도움을 받으실 수 있습니다.',
            'search_placeholder'         => '지원 문서나 FAQ 검색...',
            'read_article'               => '문서 읽기',
            'featured_articles'          => '추천 문서',
            'most_viewed_articles'       => '가장 많이 본 문서',
            'question'                   => '질문',
            'last_updated_on'            => '마지막 업데이트',
        ],

        /*
        |--------------------------------------------------------------------------
        | Batch Actions
        |--------------------------------------------------------------------------
        */
        'batch_actions'        => [
            'select_action'                         => '배치 작업 선택',
            'select_action_warning'                 => '배치 작업을 선택해주세요.',
            'select_items_warning'                  => '최소 하나의 항목을 선택해주세요.',
            'confirm_title'                         => '확인',
            'confirm_text'                          => '선택한 항목에 대해 :action 작업을 수행하시겠습니까?',
            'you_will_be_able_to_revert_this_later' => '나중에 되돌릴 수 없습니다.',
            'bulk_actions'                          => '대량 작업',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Analytics
        |--------------------------------------------------------------------------
        */
        'analytics'            => [
            'new_tickets'              => '새 티켓',
            'agent_responses'          => '담당자 응답',
            'resolved_tickets'         => '해결된 티켓',
            'analytics_overview'       => '분석 개요',
            'all_categories'           => '모든 카테고리',
            'all_agents'               => '모든 담당자',
            'apply_filters'            => '필터 적용',
            'no_change_from_yesterday' => '어제와 동일',
            'from_yesterday'           => '어제부터',
            'agent_replies'            => '담당자 답변',
            'updated'                  => '분석 데이터가 성공적으로 업데이트되었습니다!',
            'date_range'               => '날짜 범위',
            'select_date_range'        => '날짜 범위 선택',
            'agent'                    => '담당자',
        ],

        /*
        |--------------------------------------------------------------------------
        | Policy Denial Messages
        |--------------------------------------------------------------------------
        */
        'policies'             => [
            'support_categories' => [
                'view_any'     => '지원 카테고리를 볼 권한이 없습니다.',
                'view'         => '이 지원 카테고리를 볼 권한이 없습니다.',
                'create'       => '지원 카테고리를 생성할 권한이 없습니다.',
                'update'       => '지원 카테고리를 업데이트할 권한이 없습니다.',
                'delete'       => '지원 카테고리를 삭제할 권한이 없습니다.',
                'delete_any'   => '여러 지원 카테고리를 삭제할 권한이 없습니다.',
                'batch_action' => '지원 카테고리에 대한 배치 작업 권한이 없습니다.',
            ],
            'support_articles'   => [
                'view_any'     => '지원 문서를 볼 권한이 없습니다.',
                'view'         => '이 지원 문서를 볼 권한이 없습니다.',
                'create'       => '지원 문서를 생성할 권한이 없습니다.',
                'update'       => '지원 문서를 업데이트할 권한이 없습니다.',
                'delete'       => '지원 문서를 삭제할 권한이 없습니다.',
                'delete_any'   => '여러 지원 문서를 삭제할 권한이 없습니다.',
                'batch_action' => '지원 문서에 대한 배치 작업 권한이 없습니다.',
                'publish'      => '이 지원 문서를 게시/미게시할 권한이 없습니다.',
                'feature'      => '이 지원 문서를 추천/비추천할 권한이 없습니다.',
            ],
            'support_tickets'    => [
                'view_any'      => '지원 티켓을 볼 권한이 없습니다.',
                'view'          => '이 지원 티켓을 볼 권한이 없습니다.',
                'create'        => '지원 티켓을 생성할 권한이 없습니다.',
                'update'        => '이 티켓을 업데이트할 권한이 없습니다.',
                'delete'        => '이 티켓을 삭제할 권한이 없습니다.',
                'reply'         => '이 티켓에 답변할 권한이 없거나 티켓이 닫혀 있습니다.',
                'update_status' => '티켓 상태를 업데이트할 권한이 없습니다.',
                'assign'        => '이 티켓을 할당할 권한이 없습니다.',
                'manage_tags'   => '이 티켓의 태그를 관리할 권한이 없습니다.',
                'delete_any'    => '여러 티켓을 삭제할 권한이 없습니다.',
                'batch_action'  => '티켓에 대한 배치 작업 권한이 없습니다.',
            ],
            'ticket_replies'     => [
                'update'     => '이 답변을 업데이트할 권한이 없거나 편집 기간이 만료되었습니다.',
                'delete'     => '이 답변을 삭제할 권한이 없습니다.',
                'delete_any' => '여러 티켓 답변을 삭제할 권한이 없습니다.',
            ],
            'ticket_attachments' => [
                'view'       => '이 첨부파일을 볼 권한이 없습니다.',
                'delete'     => '이 첨부파일을 삭제할 권한이 없습니다.',
                'delete_any' => '여러 첨부파일을 삭제할 권한이 없습니다.',
            ],
        ],

    ];
