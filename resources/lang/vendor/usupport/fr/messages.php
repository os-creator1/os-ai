<?php

    return [

        // General messages
        'something_went_wrong' => 'Algo salió mal. Por favor, inténtelo de nuevo más tarde.',
        'permission_denied'    => 'No tienes permiso para realizar esta acción.',
        'operation_successful' => '¡Operación completada con éxito!',

        // Global Labels
        'last_modified'        => 'Última modificación',
        'description'          => 'Descripción',
        'slug'                 => 'Slug',
        'is_active'            => '¿Está activo?',
        'add_new'              => 'Agregar nuevo',
        'edit'                 => 'Editar',
        'back_to_list'         => 'Volver a la lista',
        'select_category'      => '-- Seleccionar categoría --',
        'uncategorized'        => 'Sin categoría',
        'unknown_user'         => 'Usuario desconocido',
        'confirm_delete_title' => '¿Estás seguro?',
        'you'                  => 'Tú',
        'updated'              => 'Actualizado',

        // Buttons
        'buttons'              => [
            'open'       => 'Abrir',
            'reply'      => 'Responder',
            'publish'    => 'Publicar',
            'unpublish'  => 'Despublicar',
            'feature'    => 'Destacar',
            'unfeature'  => 'Quitar destacado',
            'deactivate' => 'Desactivar',
        ],

        // Support Settings
        'settings'             => [
            'support_desk_name'               => 'Nombre del soporte',
            'support_desk_name_help'          => 'Se usa en todo el sistema de soporte, en correos electrónicos, etc.',
            'support_desk_email'              => 'Correo de soporte',
            'support_desk_email_help'         => 'Solo se usa para enviar actualizaciones importantes.',
            'title'                           => 'Configuración de soporte',
            'ticket_settings'                 => 'Configuración de tickets',
            'ticket_tags'                     => 'Etiquetas de tickets',
            'privacy_settings'                => 'Configuración de privacidad',
            'important_notice_messages'       => 'Mensajes de aviso importante',
            'auto_reassign'                   => 'Reasignar automáticamente tickets al agente de soporte que respondió',
            'filter_by_category'              => 'Filtrar la lista de artículos por categoría dentro del cuadro de respuesta del ticket',
            'disable_public_tickets'          => 'Desactivar tickets públicos <span class="text-muted small">(Los tickets públicos actuales no se verán afectados)</span>',
            'public_tickets_default'          => 'Los tickets públicos son los predeterminados (en lugar de privados)',
            'order_by_last_updated'           => 'Ordenar tickets ascendente por fecha de última actualización',
            'group_by_last_updated'           => 'Agrupar tickets por fecha de última actualización',
            'enable_autoresponder'            => 'Habilitar mensaje de respuesta automática',
            'enable_important_notice_message' => 'Habilitar mensaje de aviso importante',
            'updated_successfully'            => 'Configuración actualizada correctamente.',
            'ticket_tag_updated_successfully' => 'Etiqueta de ticket actualizada correctamente.',
        ],

        // Support Agents
        'agents'               => [
            'open_tickets'                       => 'Tickets abiertos',
            'closed_tickets'                     => 'Tickets cerrados',
            'last_signed_in'                     => 'Último acceso',
            'statistics'                         => 'Estadísticas',
            'assign_agent'                       => 'Asignar agente',
            'edit_agent'                         => 'Editar agente',
            'designation'                        => 'Cargo',
            'bio'                                => 'Biografía',
            'description'                        => 'Primero crea un rol desde <a href=":role_url" target="_blank">Crear Rol</a>, luego crea un administrador desde <a href=":administrator_url" target="_blank">Crear Administrador</a>. Asigna el rol creado al administrador y finalmente designa al administrador como Agente de soporte.',
            'assigned_successfully'              => 'Agente asignado correctamente.',
            'already_assigned_or_no_change'      => 'El agente ya está asignado o no hubo cambios.',
            'never_logged_in'                    => 'Nunca ha iniciado sesión',
            'confirm_activate_text'              => '¿Deseas activar realmente estos agentes?',
            'confirm_deactivate_text'            => '¿Deseas desactivar realmente estos agentes?',
            'confirm_delete_text'                => '¿Deseas liberar realmente a estos agentes de soporte?',
            'yes_activate_it'                    => '¡Sí, activarlo!',
            'yes_deactivate_it'                  => '¡Sí, desactivarlo!',
            'yes_release_it'                     => '¡Sí, liberarlo!',
            'deactivated_successfully'           => 'Agente desactivado correctamente.',
            'updated_successfully'               => 'Agente actualizado correctamente.',
            'deleted_successfully'               => 'Administrador liberado del rol de agente de soporte correctamente.',
            'no_valid_selected_for_deactivation' => 'No se seleccionaron agentes válidos para desactivación.',
            'activate_selected'                  => 'Activar seleccionados',
            'activated_multiple_successfully'    => ':count agentes han sido activados correctamente.',
            'deactivate_selected'                => 'Desactivar seleccionados',
            'deactivated_multiple_successfully'  => ':count agentes han sido desactivados correctamente.',
            'delete_selected'                    => 'Liberar seleccionados',
            'deleted_multiple_successfully'      => ':count agentes han sido liberados del rol de agente de soporte correctamente.',
        ],

        // Support Categories
        'categories'           => [
            'title_singular'                => 'Categoría de soporte',
            'title_plural'                  => 'Categorías de soporte',
            'name'                          => 'Nombre de la categoría',
            'icon'                          => 'Icono',
            'name_placeholder'              => 'ej: Problemas técnicos, Consultas de facturación',
            'slug_placeholder'              => 'ej: problemas-tecnicos',
            'description_placeholder'       => 'Describe brevemente la categoría',
            'add_new'                       => 'Agregar nueva categoría',
            'edit'                          => 'Editar categoría',
            'details'                       => 'Detalles de la categoría',
            'select_icon'                   => 'Seleccionar un icono',
            'search_icons'                  => 'Buscar iconos...',
            'created_successfully'          => 'Categoría de soporte creada correctamente.',
            'updated_successfully'          => 'Categoría de soporte actualizada correctamente.',
            'deleted_successfully'          => 'Categoría de soporte eliminada correctamente.',
            'confirm_activate'              => '¿Seguro que deseas activar las categorías seleccionadas?',
            'confirm_deactivate'            => '¿Seguro que deseas desactivar las categorías seleccionadas?',
            'confirm_delete'                => '¿Seguro que deseas eliminar permanentemente las categorías seleccionadas?',
            'activated_successfully'        => ':count categorías activadas correctamente.',
            'deactivated_successfully'      => ':count categorías desactivadas correctamente.',
            'deleted_multiple_successfully' => ':count categorías eliminadas correctamente.',
            'slug_auto_generated_help'      => 'El slug se generará automáticamente desde el nombre si se deja vacío.',
            'browse_by_category'            => 'Explorar por categoría',
            'published_on'                  => 'Publicado el',
            'related_questions'             => 'Preguntas relacionadas',
        ],


        /*
|--------------------------------------------------------------------------
| Support Tickets
|--------------------------------------------------------------------------
*/
        'tickets'              => [
            'title_singular'                        => 'Ticket de Support',
            'title_plural'                          => 'Tickets de Support',
            'create_ticket'                         => 'Créer un Ticket',
            'view_ticket'                           => 'Voir le Ticket',
            'created_successfully'                  => 'Ticket de support créé avec succès !',
            'updated_successfully'                  => 'Ticket de support mis à jour avec succès !',
            'deleted_successfully'                  => 'Ticket de support supprimé avec succès !',
            'status_updated_successfully'           => 'Statut du ticket mis à jour avec succès !',
            'assigned_successfully'                 => 'Ticket assigné avec succès !',
            'tags_updated_successfully'             => 'Tags du ticket mis à jour avec succès !',
            'confirm_action'                        => 'Vous êtes sur le point de :action :length tickets.',
            'opened_successfully'                   => ':count tickets ouverts avec succès !',
            'closed_successfully'                   => ':count tickets fermés avec succès !',
            'marked_pending_successfully'           => ':count tickets marqués en attente avec succès !',
            'assigned_multiple_successfully'        => ':count tickets assignés à :agent avec succès !',
            'deleted_multiple_successfully'         => ':count tickets supprimés avec succès !',
            'starred_successfully'                  => 'Statut étoilé du ticket mis à jour avec succès !',
            'category'                              => 'Catégorie',
            'assigned_agent'                        => 'Agent Assigné',
            'last_replied'                          => 'Dernière réponse',
            'priority'                              => 'Priorité',
            'customer_name'                         => 'Nom du Client',
            'customer_email'                        => 'Email du Client',
            'please_select_an_agent_for_assignment' => 'Veuillez sélectionner un agent pour l’assignation.',
            'search_placeholder'                    => 'Rechercher des tickets...',
            'filter_by_status'                      => 'Filtrer par statut',
            'filter_by_priority'                    => 'Filtrer par priorité',
            'assigned_to'                           => 'Assigné à :agent_name',
            'unassigned'                            => 'Non assigné',
            'last_updated'                          => 'Mis à jour il y a :time',
            'no_tickets_found'                      => 'Aucun ticket trouvé correspondant à vos critères.',
            'category_help'                         => 'De quelle catégorie avez-vous besoin d’aide ?',
            'subject_help'                          => 'En général, de quoi s’agit ce ticket ?',
            'subject_placeholder'                   => 'De quoi s’agit ce ticket ?',
            'related_url'                           => 'URL associée',
            'related_url_help'                      => 'Optionnel, mais très utile.',
            'description_help'                      => 'Veuillez être aussi descriptif que possible concernant les détails de ce ticket.',
            'description_placeholder'               => 'Comment pouvons-nous vous aider aujourd’hui ?',
            'agent_help'                            => 'Assignez éventuellement un agent à ce ticket.',
            'select_agent'                          => 'Sélectionner un agent',
            'file_type_not_allowed'                 => 'Type de fichier non autorisé.',
            'file_extension_not_allowed'            => 'Extension de fichier non autorisée.',
            'file_too_large'                        => 'Le fichier est trop volumineux. Taille maximale autorisée : :max_size.',
            'one_or_more_files_invalid'             => 'Un ou plusieurs fichiers sélectionnés sont invalides (type ou taille). Veuillez vérifier et réessayer.',
            'max_attachments_exceeded'              => 'Vous pouvez télécharger un maximum de :max pièces jointes.',
            'make_public'                           => 'Rendre ce ticket public',
            'is_public_customer_notes'              => 'Par défaut, seul le support peut voir et répondre à vos tickets.',
            'is_public_help'                        => '<i>Un ticket public,</i> permet toutefois à toute la communauté de voir et répondre. <b>Notez qu’elle ne peut pas voir les informations saisies dans les champs "privés" ci-dessus.</b>',
            'status_help'                           => 'Statut actuel du ticket.',
            'select_status'                         => 'Sélectionner le statut',
            'priority_help'                         => 'Niveau d’importance du ticket.',
            'select_priority'                       => 'Sélectionner la priorité',
            'customer_help'                         => 'Sélectionnez le client pour lequel ce ticket est créé.',
            'select_customer'                       => 'Sélectionner un client',
            'private_ticket'                        => 'TICKET PRIVÉ',
            'original_description'                  => 'Description originale du ticket',
            'no_replies_yet'                        => 'Aucune réponse ou note ajoutée pour l’instant.',
            'post_a_reply'                          => 'Poster une réponse',
            'post_a_note'                           => 'Poster une note',
            'reply_placeholder'                     => 'Ajouter à cette conversation...',
            'private_note'                          => 'Note privée',
            'internal_note'                         => 'Note interne',
            'public_reply'                          => 'Réponse publique',
            'post_reply'                            => 'Poster la réponse',
            'post_note'                             => 'Poster la note',
            'ticket_details'                        => 'DÉTAILS DU TICKET',
            'contact'                               => 'Contact',
            'assigned'                              => 'ASSIGNÉ',
            'created'                               => 'CRÉÉ',
            'response'                              => 'RÉPONSE',
            'public_ticket'                         => 'TICKET PUBLIC',
            'private'                               => 'Privé',
            'public'                                => 'Public',
            'privately_reply'                       => 'Répondre en privé',
            'add_customer_note_btn'                 => 'Ajouter une note client',
            'no_response_yet'                       => 'Pas encore de réponse',
            'delete_ticket'                         => 'Supprimer ce ticket',
            'ticket_tags'                           => 'TAGS DU TICKET',
            'select_some_options'                   => 'Sélectionner quelques options',
            'reply_added_successfully'              => 'Réponse ajoutée avec succès !',
            'attachments'                           => 'Pièces jointes',
            'needs_response'                        => 'Nécessite une réponse',
            'customer_notes'                        => 'Notes du client',
            'close_ticket'                          => 'Fermer le ticket',
            'ticket_closed_successfully'            => 'Ticket fermé avec succès !',
            'select_tags'                           => 'Sélectionner des tags',
            'update_priority_successfully'          => 'Priorité du ticket mise à jour avec succès !',
            'category_updated_successfully'         => 'Catégorie du ticket mise à jour avec succès !',
            'replied_privately'                     => 'Répondu en privé',
            'privacy_policy_note'                   => 'Lisez notre <a href=":privacy_policy_url" target="_blank">Politique de Confidentialité</a> pour savoir comment nous traitons vos données personnelles',
            'all_cleared'                           => 'Tout effacé.',

            'statuses'   => [
                'pending'    => 'En attente',
                'open'       => 'Ouvert',
                'resolved'   => 'Résolu',
                'closed'     => 'Fermé',
                'replied'    => 'Répondu (-par le client)',
                'starred'    => 'Étoilé',
                'unassigned' => 'Non assigné',
            ],
            'priorities' => [
                'low'    => 'Faible',
                'medium' => 'Moyenne',
                'high'   => 'Haute',
                'urgent' => 'Urgent',
            ],
            'filters'    => [
                'title'               => 'Filtres',
                'all_tickets'         => 'Tous les tickets',
                'my_tickets'          => 'Mes tickets',
                'unassigned_tickets'  => 'Tickets non assignés',
                'starred_tickets'     => 'Tickets étoilés',
                'categories'          => 'Catégories',
                'no_categories_found' => 'Aucune catégorie trouvée',
                'agents'              => 'Agents',
                'no_agents_found'     => 'Aucun agent trouvé',
            ],
            'stats'      => [
                'title'              => 'Statistiques rapides',
                'total_tickets'      => 'Nombre total de tickets',
                'open_tickets'       => 'Tickets ouverts',
                'closed_last_7_days' => 'Fermés (7 derniers jours)',
                'closed_older_than'  => 'Plus anciens que 7 jours',
                'closed_other'       => 'Dans les 7 derniers jours (autres)',
                'closed_2days'       => 'Mis à jour il y a 2 jours',
                'updated_today'      => 'Mis à jour aujourd’hui',
                'no_action_needed'   => 'Aucune action nécessaire',
            ],
        ],

        /*
|--------------------------------------------------------------------------
| Ticket Replies
|--------------------------------------------------------------------------
*/
        'replies'              => [
            'added_successfully'        => 'Réponse ajoutée avec succès !',
            'message'                   => 'Message de réponse',
            'add_reply'                 => 'Ajouter une réponse',
            'your_reply'                => 'Votre réponse',
            'title_plural'              => 'Réponses',
            'edited'                    => 'Édité',
            'note_updated_successfully' => 'Note mise à jour avec succès.',
            'fetched_successfully'      => 'Réponse récupérée avec succès.',
            'not_found'                 => 'Cette réponse n’appartient pas au ticket spécifié.',
            'updated_successfully'      => 'Réponse mise à jour avec succès.',
            'deleted_successfully'      => 'Réponse supprimée avec succès.',
            'delete_failed'             => 'Échec de la suppression de la réponse.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Attachments
        |--------------------------------------------------------------------------
        */
        'attachments'          => [
            'title_plural'         => 'Pièces jointes',
            'add_attachments'      => 'Ajouter des pièces jointes',
            'not_found'            => 'Cette pièce jointe n’appartient pas au ticket spécifié.',
            'deleted_successfully' => 'Pièce jointe supprimée avec succès !',
            'delete_failed'        => 'Échec de la suppression de la pièce jointe.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Tags
        |--------------------------------------------------------------------------
        */
        'tags'                 => [
            'title_singular'                => 'Tag de ticket',
            'title_plural'                  => 'Tags de ticket',
            'name'                          => 'Nom du tag',
            'name_placeholder'              => 'Saisir le nom du tag',
            'description'                   => 'Description du tag',
            'color'                         => 'Couleur',
            'add_new'                       => 'Ajouter un nouveau tag',
            'edit'                          => 'Éditer le tag',
            'created_successfully'          => 'Tag créé avec succès !',
            'updated_successfully'          => 'Tag mis à jour avec succès !',
            'deleted_successfully'          => 'Tag supprimé avec succès !',
            'deleted_multiple_successfully' => ':count tags supprimés avec succès !',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Articles
        |--------------------------------------------------------------------------
        */
        'articles'             => [
            'title_singular'             => 'Article de support',
            'title_plural'               => 'Articles de support',
            'create'                     => 'Créer un article de support',
            'edit'                       => 'Éditer un article de support',
            'published_status'           => 'Statut de publication',
            'featured_status'            => 'Statut en vedette',
            'draft'                      => 'Brouillon',
            'published'                  => 'Publié',
            'featured'                   => 'En vedette',
            'views'                      => 'Vues',
            'confirm_delete_text'        => 'Vous ne pourrez pas récupérer cet article de support !',
            'deleted_successfully'       => 'Article de support supprimé avec succès.',
            'delete_failed'              => 'Échec de la suppression de l’article de support.',
            'created_successfully'       => 'Article de support créé avec succès !',
            'updated_successfully'       => 'Article de support mis à jour avec succès !',
            'update_failed'              => 'Échec de la mise à jour de l’article de support.',
            'published_successfully'     => 'Article publié avec succès.',
            'unpublished_successfully'   => 'Article dépublié avec succès.',
            'featured_successfully'      => 'Article mis en vedette avec succès.',
            'unfeatured_successfully'    => 'Article retiré des vedettes avec succès.',
            'slug_help'                  => 'Laisser vide pour générer automatiquement depuis le titre. Doit être unique.',
            'is_published_help'          => 'Laisser décoché pour enregistrer en brouillon',
            'confirm_publish'            => 'Êtes-vous sûr de vouloir publier les articles sélectionnés ?',
            'confirm_unpublish'          => 'Êtes-vous sûr de vouloir dépublier les articles sélectionnés ?',
            'confirm_feature'            => 'Êtes-vous sûr de vouloir mettre en vedette les articles sélectionnés ?',
            'confirm_unfeature'          => 'Êtes-vous sûr de vouloir retirer les articles sélectionnés des vedettes ?',
            'confirm_delete'             => 'Êtes-vous sûr de vouloir supprimer définitivement les articles sélectionnés ?',
            'batch_action_success'       => ':count articles ont été :action avec succès.',
            'batch_action_failed'        => 'Échec de l’action groupée.',
            'batch_action_failed_no_ids' => 'Échec de l’action groupée. Aucun ID fourni.',
            'help_text'                  => 'Bienvenue dans le support ! Nous avons :count articles pour vous aider',
            'search_placeholder'         => 'Recherchez dans nos articles d’aide ou FAQ...',
            'read_article'               => 'Lire l’article',
            'featured_articles'          => 'Articles en vedette',
            'most_viewed_articles'       => 'Articles les plus consultés',
            'question'                   => 'Question',
            'last_updated_on'            => 'Dernière mise à jour le',
        ],

        /*
        |--------------------------------------------------------------------------
        | Batch Actions - General messages for all batch actions
        |--------------------------------------------------------------------------
        */
        'batch_actions'        => [
            'select_action'                         => 'Sélectionner une action groupée',
            'select_action_warning'                 => 'Veuillez sélectionner une action groupée.',
            'select_items_warning'                  => 'Veuillez sélectionner au moins un élément.',
            'confirm_title'                         => 'Êtes-vous sûr ?',
            'confirm_text'                          => 'Vous êtes sur le point d’exécuter une action groupée : :action sur les éléments sélectionnés.',
            'you_will_be_able_to_revert_this_later' => 'Vous ne pourrez pas revenir en arrière plus tard.',
            'bulk_actions'                          => 'Actions groupées',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Analytics
        |--------------------------------------------------------------------------
        */
        'analytics'            => [
            'new_tickets'              => 'Nouveaux tickets',
            'agent_responses'          => 'Réponses des agents',
            'resolved_tickets'         => 'Tickets résolus',
            'analytics_overview'       => 'Aperçu analytique',
            'all_categories'           => 'Toutes les catégories',
            'all_agents'               => 'Tous les agents',
            'apply_filters'            => 'Appliquer les filtres',
            'no_change_from_yesterday' => 'Pas de changement par rapport à hier',
            'from_yesterday'           => 'Depuis hier',
            'agent_replies'            => 'Réponses des agents',
            'updated'                  => 'Données analytiques mises à jour avec succès !',
            'date_range'               => 'Plage de dates',
            'select_date_range'        => 'Sélectionner la plage de dates',
            'agent'                    => 'Agent',
        ],

        /*
        |--------------------------------------------------------------------------
        | Policy Denial Messages
        |--------------------------------------------------------------------------
        */
        'policies'             => [
            'support_categories' => [
                'view_any'     => 'Vous n’avez pas la permission de voir les catégories de support.',
                'view'         => 'Vous n’avez pas la permission de voir cette catégorie de support.',
                'create'       => 'Vous n’avez pas la permission de créer des catégories de support.',
                'update'       => 'Vous n’avez pas la permission de mettre à jour cette catégorie de support.',
                'delete'       => 'Vous n’avez pas la permission de supprimer cette catégorie de support.',
                'delete_any'   => 'Vous n’avez pas la permission de supprimer plusieurs catégories de support.',
                'batch_action' => 'Vous n’avez pas la permission d’exécuter des actions groupées sur les catégories de support.',
            ],
            'support_articles'   => [
                'view_any'     => 'Vous n’avez pas la permission de voir les articles de support.',
                'view'         => 'Vous n’avez pas la permission de voir cet article de support.',
                'create'       => 'Vous n’avez pas la permission de créer des articles de support.',
                'update'       => 'Vous n’avez pas la permission de mettre à jour cet article de support.',
                'delete'       => 'Vous n’avez pas la permission de supprimer cet article de support.',
                'delete_any'   => 'Vous n’avez pas la permission de supprimer plusieurs articles de support.',
                'batch_action' => 'Vous n’avez pas la permission d’exécuter des actions groupées sur les articles de support.',
                'publish'      => 'Vous n’avez pas la permission de publier ou dépublier cet article de support.',
                'feature'      => 'Vous n’avez pas la permission de mettre en vedette ou retirer cet article de support des vedettes.',
            ],
            'support_tickets'    => [
                'view_any'      => 'Vous n’avez pas la permission de voir les tickets de support.',
                'view'          => 'Vous n’avez pas la permission de voir ce ticket de support.',
                'create'        => 'Vous n’avez pas la permission de créer des tickets de support.',
                'update'        => 'Vous n’avez pas la permission de mettre à jour ce ticket.',
                'delete'        => 'Vous n’avez pas la permission de supprimer ce ticket.',
                'reply'         => 'Vous n’avez pas la permission de répondre à ce ticket ou il est fermé.',
                'update_status' => 'Vous n’avez pas la permission de mettre à jour le statut de ce ticket.',
                'assign'        => 'Vous n’avez pas la permission d’assigner ce ticket.',
                'manage_tags'   => 'Vous n’avez pas la permission de gérer les tags de ce ticket.',
                'delete_any'    => 'Vous n’avez pas la permission de supprimer plusieurs tickets de support.',
                'batch_action'  => 'Vous n’avez pas la permission d’exécuter des actions groupées sur les tickets de support.',
            ],
            'ticket_replies'     => [
                'update'     => 'Vous n’avez pas la permission de mettre à jour cette réponse ou le délai d’édition est expiré.',
                'delete'     => 'Vous n’avez pas la permission de supprimer cette réponse.',
                'delete_any' => 'Vous n’avez pas la permission de supprimer plusieurs réponses de ticket.',
            ],
            'ticket_attachments' => [
                'view'       => 'Vous n’avez pas la permission de voir cette pièce jointe.',
                'delete'     => 'Vous n’avez pas la permission de supprimer cette pièce jointe.',
                'delete_any' => 'Vous n’avez pas la permission de supprimer plusieurs pièces jointes de ticket.',
            ],
        ],

    ];
