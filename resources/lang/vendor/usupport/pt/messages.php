<?php

    return [

// Mensagens Gerais
        'something_went_wrong' => 'Algo deu errado. Por favor, tente novamente mais tarde.',
        'permission_denied'    => 'Você não tem permissão para executar esta ação.',
        'operation_successful' => 'Operação concluída com sucesso!',

        // Rótulos Globais
        'last_modified'        => 'Última Modificação',
        'description'          => 'Descrição', // Rótulo geral de descrição
        'slug'                 => 'Slug',       // Rótulo geral de slug
        'is_active'            => 'Está ativo?', // Rótulo geral de status ativo
        'add_new'              => 'Adicionar Novo',    // Rótulo geral de adicionar novo
        'edit'                 => 'Editar',            // Rótulo geral de editar
        'back_to_list'         => 'Voltar para a Lista',
        'select_category'      => '-- Selecione a Categoria --',
        'uncategorized'        => 'Sem Categoria',
        'unknown_user'         => 'Usuário Desconhecido',
        'confirm_delete_title' => 'Você tem certeza?', // Título geral de confirmação
        'you'                  => 'Você',
        'updated'              => 'Atualizado',

        // Botões
        'buttons'              => [
            'open'       => 'Abrir',
            'reply'      => 'Responder',
            'publish'    => 'Publicar',
            'unpublish'  => 'Despublicar',
            'feature'    => 'Destacar',
            'unfeature'  => 'Remover Destaque',
            'deactivate' => 'Desativar',
        ],

        /*
        |--------------------------------------------------------------------------
        | Configurações de Suporte
        |--------------------------------------------------------------------------
        */
        'settings'             => [
            'support_desk_name'               => 'Nome da Central de Suporte',
            'support_desk_name_help'          => 'Usado em todo o sistema de suporte, em e-mails, etc.',
            'support_desk_email'              => 'E-mail da Central de Suporte',
            'support_desk_email_help'         => 'Usado apenas para enviar atualizações importantes.',
            'title'                           => 'Configurações de Suporte', // Título geral da página de configurações
            'ticket_settings'                 => 'Configurações de Chamados',
            'ticket_tags'                     => 'Tags de Chamados',
            'privacy_settings'                => 'Configurações de Privacidade',
            'important_notice_messages'       => 'Mensagens de Aviso Importante',
            'auto_reassign'                   => 'Reatribuir automaticamente os chamados ao agente de suporte que respondeu',
            'filter_by_category'              => 'Filtrar lista de artigos por categoria dentro da caixa de resposta do chamado',
            'disable_public_tickets'          => 'Desativar Chamados Públicos <span class="text-muted small">(Chamados públicos atuais não serão afetados)</span>',
            'public_tickets_default'          => 'Chamados públicos são o padrão (em vez de privados)',
            'order_by_last_updated'           => 'Ordenar chamados de forma ascendente pela última data de atualização',
            'group_by_last_updated'           => 'Agrupar chamados pela última data de atualização',
            'enable_autoresponder'            => 'Ativar Mensagem de Resposta Automática',
            'enable_important_notice_message' => 'Ativar Mensagem de Aviso Importante',
            'updated_successfully'            => 'Configurações atualizadas com sucesso.',
            'ticket_tag_updated_successfully' => 'Tag de chamado atualizada com sucesso.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Agentes de Suporte
        |--------------------------------------------------------------------------
        */
        'agents'               => [
            'open_tickets'                       => 'Chamados Abertos',
            'closed_tickets'                     => 'Chamados Fechados',
            'last_signed_in'                     => 'Último Acesso',
            'statistics'                         => 'Estatísticas',
            'assign_agent'                       => 'Atribuir Agente',
            'edit_agent'                         => 'Editar Agente',
            'designation'                        => 'Cargo',
            'bio'                                => 'Biografia',
            'description'                        => 'Primeiro, crie uma função em <a href=":role_url" target="_blank">Criar Função</a>, depois crie um administrador em <a href=":administrator_url" target="_blank">Criar Administrador</a>. Atribua a função criada ao administrador e, por fim, designe o administrador como Agente de Suporte.',
            'assigned_successfully'              => 'Agente atribuído com sucesso.',
            'already_assigned_or_no_change'      => 'O agente já está atribuído ou nenhuma alteração ocorreu.',
            'never_logged_in'                    => 'Nunca Acessou',
            'confirm_activate_text'              => 'Você realmente deseja ativar esses agentes?',
            'confirm_deactivate_text'            => 'Você realmente deseja desativar esses agentes?',
            'confirm_delete_text'                => 'Você realmente deseja remover esses agentes do suporte?',
            'yes_activate_it'                    => 'Sim, ativar!',
            'yes_deactivate_it'                  => 'Sim, desativar!',
            'yes_release_it'                     => 'Sim, remover!',
            'deactivated_successfully'           => 'Agente desativado com sucesso.',
            'updated_successfully'               => 'Agente atualizado com sucesso.',
            'deleted_successfully'               => 'Administrador removido da função de agente de suporte com sucesso.',
            'no_valid_selected_for_deactivation' => 'Nenhum agente válido selecionado para desativação.',
            'activate_selected'                  => 'Ativar Selecionados',
            'activated_multiple_successfully'    => ':count agentes foram ativados com sucesso.',
            'deactivate_selected'                => 'Desativar Selecionados',
            'deactivated_multiple_successfully'  => ':count agentes foram desativados com sucesso.',
            'delete_selected'                    => 'Remover Selecionados',
            'deleted_multiple_successfully'      => ':count agentes foram removidos da função de agente de suporte com sucesso.',
        ],
        /*
        |--------------------------------------------------------------------------
        | Categorias de Suporte
        |--------------------------------------------------------------------------
        */
        'categories'           => [
            'title_singular'                => 'Categoria de Suporte',
            'title_plural'                  => 'Categorias de Suporte',
            'name'                          => 'Nome da Categoria',
            'icon'                          => 'Ícone',
            'name_placeholder'              => 'ex.: Problemas Técnicos, Dúvidas de Cobrança',
            'slug_placeholder'              => 'ex.: problemas-tecnicos',
            'description_placeholder'       => 'Descreva brevemente a categoria',
            'add_new'                       => 'Adicionar Nova Categoria',
            'edit'                          => 'Editar Categoria',
            'details'                       => 'Detalhes da Categoria',
            'select_icon'                   => 'Selecionar um Ícone',
            'search_icons'                  => 'Procurar ícones...',
            'created_successfully'          => 'Categoria de suporte criada com sucesso!',
            'updated_successfully'          => 'Categoria de suporte atualizada com sucesso!',
            'deleted_successfully'          => 'Categoria de suporte excluída com sucesso!',
            'confirm_activate'              => 'Tem certeza de que deseja ativar as categorias selecionadas?',
            'confirm_deactivate'            => 'Tem certeza de que deseja desativar as categorias selecionadas?',
            'confirm_delete'                => 'Tem certeza de que deseja excluir permanentemente as categorias selecionadas?',
            'activated_successfully'        => ':count categorias foram ativadas com sucesso.',
            'deactivated_successfully'      => ':count categorias foram desativadas com sucesso.',
            'deleted_multiple_successfully' => ':count categorias foram excluídas com sucesso.',
            'slug_auto_generated_help'      => 'O slug será gerado automaticamente a partir do nome se deixado em branco.',
            'browse_by_category'            => 'Navegar por Categoria',
            'published_on'                  => 'Publicado em',
            'related_questions'             => 'Perguntas Relacionadas',
        ],

        /*
        |--------------------------------------------------------------------------
        | Chamados de Suporte
        |--------------------------------------------------------------------------
        */
        'tickets'              => [
            'title_singular'                        => 'Chamado de Suporte',
            'title_plural'                          => 'Chamados de Suporte',
            'create_ticket'                         => 'Criar Chamado',
            'view_ticket'                           => 'Visualizar Chamado',
            'created_successfully'                  => 'Chamado criado com sucesso!',
            'updated_successfully'                  => 'Chamado atualizado com sucesso!',
            'deleted_successfully'                  => 'Chamado excluído com sucesso!',
            'status_updated_successfully'           => 'Status do chamado atualizado com sucesso!',
            'assigned_successfully'                 => 'Chamado atribuído com sucesso!',
            'tags_updated_successfully'             => 'Tags do chamado atualizadas com sucesso!',
            'confirm_action'                        => 'Você está prestes a :action :length chamados.',
            'opened_successfully'                   => ':count chamados abertos com sucesso!',
            'closed_successfully'                   => ':count chamados fechados com sucesso!',
            'marked_pending_successfully'           => ':count chamados marcados como pendentes com sucesso!',
            'assigned_multiple_successfully'        => ':count chamados atribuídos a :agent com sucesso!',
            'deleted_multiple_successfully'         => ':count chamados excluídos com sucesso!',
            'starred_successfully'                  => 'Status de destaque do chamado atualizado com sucesso!',
            'category'                              => 'Categoria',
            'assigned_agent'                        => 'Agente Atribuído',
            'last_replied'                          => 'Última Resposta',
            'priority'                              => 'Prioridade',
            'customer_name'                         => 'Nome do Cliente',
            'customer_email'                        => 'E-mail do Cliente',
            'please_select_an_agent_for_assignment' => 'Por favor, selecione um agente para atribuição.',
            'search_placeholder'                    => 'Buscar Chamados...',
            'filter_by_status'                      => 'Filtrar por Status',
            'filter_by_priority'                    => 'Filtrar por Prioridade',
            'assigned_to'                           => 'Atribuído a :agent_name', // :agent_name será substituído
            'unassigned'                            => 'Não Atribuído',
            'last_updated'                          => 'Atualizado há :time', // :time será substituído pelo diffForHumans()
            'no_tickets_found'                      => 'Nenhum chamado encontrado com os critérios.',
            'category_help'                         => 'Com qual categoria você precisa de ajuda?',
            'subject_help'                          => 'Em geral, sobre o que é este chamado?',
            'subject_placeholder'                   => 'Sobre o que é este chamado?',
            'related_url'                           => 'URL Relacionada',
            'related_url_help'                      => 'Opcional, mas muito útil.',
            'description_help'                      => 'Por favor, seja o mais descritivo possível em relação aos detalhes deste chamado.',
            'description_placeholder'               => 'Como podemos ajudá-lo hoje?',
            'agent_help'                            => 'Opcionalmente, atribua um agente a este chamado.',
            'select_agent'                          => 'Selecione um agente',
            'file_type_not_allowed'                 => 'Tipo de arquivo não permitido.',
            'file_extension_not_allowed'            => 'Extensão de arquivo não permitida.',
            'file_too_large'                        => 'O arquivo é muito grande. Máx.: :max_size permitido.',
            'one_or_more_files_invalid'             => 'Um ou mais arquivos selecionados são inválidos (tipo ou tamanho). Verifique e tente novamente.',
            'max_attachments_exceeded'              => 'Você pode enviar no máximo :max anexos.',
            'make_public'                           => 'Tornar este chamado público',
            'is_public_customer_notes'              => 'Por padrão, apenas a equipe de suporte pode visualizar e responder seus chamados. ',
            'is_public_help'                        => '<i>Um chamado público,</i> no entanto, permite que toda a comunidade visualize e responda. <b>Note que não poderão ver nenhuma informação inserida nos campos "privados" acima.</b>',
            'status_help'                           => 'Status atual do chamado.',
            'select_status'                         => 'Selecione o status',
            'priority_help'                         => 'Nível de importância do chamado.',
            'select_priority'                       => 'Selecione a prioridade',
            'customer_help'                         => 'Selecione o cliente para quem este chamado está sendo criado.',
            'select_customer'                       => 'Selecione um cliente',
            'private_ticket'                        => 'CHAMADO PRIVADO',
            'original_description'                  => 'Descrição Original do Chamado',
            'no_replies_yet'                        => 'Ainda não foram adicionadas respostas ou notas.',
            'post_a_reply'                          => 'Postar uma Resposta',
            'post_a_note'                           => 'Postar uma Nota',
            'reply_placeholder'                     => 'Adicionar a esta conversa...',
            'private_note'                          => 'Nota Privada',
            'internal_note'                         => 'Nota Interna',
            'public_reply'                          => 'Resposta Pública',
            'post_reply'                            => 'Postar Resposta',
            'post_note'                             => 'Postar Nota',
            'ticket_details'                        => 'DETALHES DO CHAMADO',
            'contact'                               => 'Contato',
            'assigned'                              => 'ATRIBUÍDO',
            'created'                               => 'CRIADO',
            'response'                              => 'RESPOSTA',
            'public_ticket'                         => 'CHAMADO PÚBLICO',
            'private'                               => 'Privado',
            'public'                                => 'Público',
            'privately_reply'                       => 'Responder em Privado',
            'add_customer_note_btn'                 => 'Adicionar Nota do Cliente',
            'no_response_yet'                       => 'Ainda sem resposta',
            'delete_ticket'                         => 'Excluir este chamado',
            'ticket_tags'                           => 'TAGS DO CHAMADO',
            'select_some_options'                   => 'Selecione Algumas Opções',
            'reply_added_successfully'              => 'Resposta adicionada com sucesso!',
            'attachments'                           => 'Anexos',
            'needs_response'                        => 'Precisa de Resposta',
            'customer_notes'                        => 'Notas do Cliente',
            'close_ticket'                          => 'Fechar Chamado',
            'ticket_closed_successfully'            => 'Chamado fechado com sucesso!',
            'select_tags'                           => 'Selecionar Tags',
            'update_priority_successfully'          => 'Prioridade do chamado atualizada com sucesso!',
            'category_updated_successfully'         => 'Categoria do chamado atualizada com sucesso!',
            'replied_privately'                     => 'Respondido em Privado',
            'privacy_policy_note'                   => 'Leia nossa <a href=":privacy_policy_url" target="_blank">Política de Privacidade</a> e veja como tratamos seus dados pessoais',
            'all_cleared'                           => 'Tudo limpo.',

            'statuses'   => [
                'pending'    => 'Pendente',
                'open'       => 'Aberto',
                'resolved'   => 'Resolvido',
                'closed'     => 'Fechado',
                'replied'    => 'Respondido (-pelo cliente)',
                'starred'    => 'Destacado',
                'unassigned' => 'Não Atribuído',
            ],
            'priorities' => [
                'low'    => 'Baixa',
                'medium' => 'Média',
                'high'   => 'Alta',
                'urgent' => 'Urgente',
            ],
            'filters'    => [
                'title'               => 'Filtros',
                'all_tickets'         => 'Todos os Chamados',
                'my_tickets'          => 'Meus Chamados',
                'unassigned_tickets'  => 'Chamados Não Atribuídos',
                'starred_tickets'     => 'Chamados Destacados',
                'categories'          => 'Categorias',
                'no_categories_found' => 'Nenhuma Categoria Encontrada',
                'agents'              => 'Agentes',
                'no_agents_found'     => 'Nenhum Agente Encontrado',
            ],
            'stats'      => [
                'title'              => 'Estatísticas Rápidas',
                'total_tickets'      => 'Total de Chamados',
                'open_tickets'       => 'Chamados Abertos',
                'closed_last_7_days' => 'Fechados (Últimos 7 Dias)',
                'closed_older_than'  => 'Fechados há mais de 7 Dias',
                'closed_other'       => 'Fechados nos Últimos 7 Dias (Outros)',
                'closed_2days'       => 'Atualizado há 2 Dias',
                'updated_today'      => 'Atualizado Hoje',
                'no_action_needed'   => 'Nenhuma Ação Necessária',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Respostas de Chamados
        |--------------------------------------------------------------------------
        */
        'replies'              => [
            'added_successfully'        => 'Resposta adicionada com sucesso!',
            'message'                   => 'Mensagem da Resposta',
            'add_reply'                 => 'Adicionar Resposta',
            'your_reply'                => 'Sua Resposta',
            'title_plural'              => 'Respostas',
            'edited'                    => 'Editado',
            'note_updated_successfully' => 'Nota atualizada com sucesso.',
            'fetched_successfully'      => 'Resposta recuperada com sucesso.',
            'not_found'                 => 'A resposta não pertence ao chamado especificado.',
            'updated_successfully'      => 'Resposta atualizada com sucesso.',
            'deleted_successfully'      => 'Resposta excluída com sucesso.',
            'delete_failed'             => 'Falha ao excluir a resposta.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Anexos de Chamados
        |--------------------------------------------------------------------------
        */
        'attachments'          => [
            'title_plural'         => 'Anexos',
            'add_attachments'      => 'Adicionar Anexos',
            'not_found'            => 'O anexo não pertence ao chamado especificado.',
            'deleted_successfully' => 'Anexo excluído com sucesso!',
            'delete_failed'        => 'Falha ao excluir o anexo.',
        ],

        /*
|--------------------------------------------------------------------------
| Ticket Tags
|--------------------------------------------------------------------------
*/
        'tags'                 => [
            'title_singular'                => 'Tag de Ticket',
            'title_plural'                  => 'Tags de Tickets',
            'name'                          => 'Nome da Tag',
            'name_placeholder'              => 'Digite o nome da tag', // Antes usado "enter_tag_name", unificado
            'description'                   => 'Descrição da Tag', // Descrição geral da tag
            'color'                         => 'Cor',
            'add_new'                       => 'Adicionar Nova Tag',
            'edit'                          => 'Editar Tag',
            'created_successfully'          => 'Tag criada com sucesso!',
            'updated_successfully'          => 'Tag atualizada com sucesso!',
            'deleted_successfully'          => 'Tag excluída com sucesso!',
            'deleted_multiple_successfully' => ':count tags excluídas com sucesso!',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Articles
        |--------------------------------------------------------------------------
        */
        'articles'             => [
            'title_singular'             => 'Artigo de Suporte',
            'title_plural'               => 'Artigos de Suporte',
            'create'                     => 'Criar Artigo de Suporte',
            'edit'                       => 'Editar Artigo de Suporte',
            'published_status'           => 'Status de Publicação',
            'featured_status'            => 'Status de Destaque',
            'draft'                      => 'Rascunho',
            'published'                  => 'Publicado',
            'featured'                   => 'Destacado',
            'views'                      => 'Visualizações',
            'confirm_delete_text'        => 'Você não poderá recuperar este artigo de suporte!',
            'deleted_successfully'       => 'Artigo de suporte excluído com sucesso.',
            'delete_failed'              => 'Falha ao excluir o artigo de suporte.',
            'created_successfully'       => 'Artigo de suporte criado com sucesso!',
            'updated_successfully'       => 'Artigo de suporte atualizado com sucesso!',
            'update_failed'              => 'Falha ao atualizar o artigo de suporte.',
            'published_successfully'     => 'Artigo publicado com sucesso.',
            'unpublished_successfully'   => 'Artigo despublicado com sucesso.',
            'featured_successfully'      => 'Artigo destacado com sucesso.',
            'unfeatured_successfully'    => 'Artigo removido do destaque com sucesso.',
            'slug_help'                  => 'Deixe em branco para gerar automaticamente a partir do título. Precisa ser único.',
            'is_published_help'          => 'Deixe desmarcado para salvar como rascunho',
            'confirm_publish'            => 'Tem certeza de que deseja publicar os artigos selecionados?',
            'confirm_unpublish'          => 'Tem certeza de que deseja despublicar os artigos selecionados?',
            'confirm_feature'            => 'Tem certeza de que deseja destacar os artigos selecionados?',
            'confirm_unfeature'          => 'Tem certeza de que deseja remover o destaque dos artigos selecionados?',
            'confirm_delete'             => 'Tem certeza de que deseja excluir permanentemente os artigos selecionados?',
            'batch_action_success'       => ':count artigos foram :action com sucesso.',
            'batch_action_failed'        => 'Falha na ação em lote.',
            'batch_action_failed_no_ids' => 'Falha na ação em lote. Nenhum ID fornecido.',
            'help_text'                  => 'Bem-vindo ao Suporte! Temos :count artigos para ajudar você',
            'search_placeholder'         => 'Pesquise em nossos artigos de ajuda ou FAQs...',
            'read_article'               => 'Ler Artigo',
            'featured_articles'          => 'Artigos em Destaque',
            'most_viewed_articles'       => 'Artigos Mais Visualizados',
            'question'                   => 'Pergunta',
            'last_updated_on'            => 'Última Atualização em',
        ],

        /*
        |--------------------------------------------------------------------------
        | Batch Actions - General messages for all batch actions
        |--------------------------------------------------------------------------
        */
        'batch_actions'        => [
            'select_action'                         => 'Selecione uma Ação em Lote',
            'select_action_warning'                 => 'Por favor, selecione uma ação em lote.',
            'select_items_warning'                  => 'Por favor, selecione pelo menos um item.',
            'confirm_title'                         => 'Você tem certeza?',
            'confirm_text'                          => 'Você está prestes a realizar uma ação em lote: :action nos itens selecionados.',
            'you_will_be_able_to_revert_this_later' => 'Você não poderá reverter isso depois.',
            'bulk_actions'                          => 'Ações em Lote',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Analytics
        |--------------------------------------------------------------------------
        */
        'analytics'            => [
            'new_tickets'              => 'Novos Tickets',
            'agent_responses'          => 'Respostas de Agentes',
            'resolved_tickets'         => 'Tickets Resolvidos',
            'analytics_overview'       => 'Visão Geral da Análise',
            'all_categories'           => 'Todas as Categorias',
            'all_agents'               => 'Todos os Agentes',
            'apply_filters'            => 'Aplicar Filtros',
            'no_change_from_yesterday' => 'Sem mudanças desde ontem',
            'from_yesterday'           => 'Desde ontem',
            'agent_replies'            => 'Respostas de Agentes',
            'updated'                  => 'Dados de análise atualizados com sucesso!',
            'date_range'               => 'Período',
            'select_date_range'        => 'Selecione o Período',
            'agent'                    => 'Agente',
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
                'view_any'     => 'Você não tem permissão para visualizar categorias de suporte.',
                'view'         => 'Você não tem permissão para visualizar esta categoria de suporte.',
                'create'       => 'Você não tem permissão para criar categorias de suporte.',
                'update'       => 'Você não tem permissão para atualizar esta categoria de suporte.',
                'delete'       => 'Você não tem permissão para excluir esta categoria de suporte.',
                'delete_any'   => 'Você não tem permissão para excluir múltiplas categorias de suporte.',
                'batch_action' => 'Você não tem permissão para realizar ações em lote em categorias de suporte.',
            ],
            'support_articles'   => [
                'view_any'     => 'Você não tem permissão para visualizar artigos de suporte.',
                'view'         => 'Você não tem permissão para visualizar este artigo de suporte.',
                'create'       => 'Você não tem permissão para criar artigos de suporte.',
                'update'       => 'Você não tem permissão para atualizar este artigo de suporte.',
                'delete'       => 'Você não tem permissão para excluir este artigo de suporte.',
                'delete_any'   => 'Você não tem permissão para excluir múltiplos artigos de suporte.',
                'batch_action' => 'Você não tem permissão para realizar ações em lote em artigos de suporte.',
                'publish'      => 'Você não tem permissão para publicar ou despublicar este artigo de suporte.',
                'feature'      => 'Você não tem permissão para destacar ou remover o destaque deste artigo de suporte.',
            ],

            'support_tickets'    => [
                'view_any'      => 'Você não tem permissão para visualizar tickets de suporte.',
                'view'          => 'Você não tem permissão para visualizar este ticket de suporte.',
                'create'        => 'Você não tem permissão para criar tickets de suporte.',
                'update'        => 'Você não tem permissão para atualizar este ticket.',
                'delete'        => 'Você não tem permissão para excluir este ticket.',
                'reply'         => 'Você não tem permissão para responder a este ticket ou ele está fechado.',
                'update_status' => 'Você não tem permissão para atualizar o status deste ticket.',
                'assign'        => 'Você não tem permissão para atribuir este ticket.',
                'manage_tags'   => 'Você não tem permissão para gerenciar tags deste ticket.',
                'delete_any'    => 'Você não tem permissão para excluir múltiplos tickets de suporte.',
                'batch_action'  => 'Você não tem permissão para realizar ações em lote em tickets de suporte.',
            ],
            'ticket_replies'     => [
                'update'     => 'Você não tem permissão para atualizar esta resposta, ou o tempo de edição expirou.',
                'delete'     => 'Você não tem permissão para excluir esta resposta.',
                'delete_any' => 'Você não tem permissão para excluir múltiplas respostas de tickets.',
            ],
            'ticket_attachments' => [
                'view'       => 'Você não tem permissão para visualizar este anexo.',
                'delete'     => 'Você não tem permissão para excluir este anexo.',
                'delete_any' => 'Você não tem permissão para excluir múltiplos anexos de tickets.',
            ],
        ],


    ];
