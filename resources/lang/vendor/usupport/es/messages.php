<<?php

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
            'unfeature'  => 'Quitar destaque',
            'deactivate' => 'Desactivar',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Settings
        |--------------------------------------------------------------------------
        */
        'settings'             => [
            'support_desk_name'               => 'Nombre del Soporte',
            'support_desk_name_help'          => 'Se utiliza en todo tu sistema de soporte, correos electrónicos, etc.',
            'support_desk_email'              => 'Correo del Soporte',
            'support_desk_email_help'         => 'Solo se utiliza para enviar actualizaciones importantes.',
            'title'                           => 'Configuración de Soporte',
            'ticket_settings'                 => 'Configuración de Tickets',
            'ticket_tags'                     => 'Etiquetas de Tickets',
            'privacy_settings'                => 'Configuración de Privacidad',
            'important_notice_messages'       => 'Mensajes de Aviso Importante',
            'auto_reassign'                   => 'Reasignar automáticamente los tickets al agente que respondió',
            'filter_by_category'              => 'Filtrar la lista de artículos por categoría dentro del cuadro de respuesta del ticket',
            'disable_public_tickets'          => 'Deshabilitar Tickets Públicos <span class="text-muted small">(Los tickets públicos actuales no se verán afectados)</span>',
            'public_tickets_default'          => 'Los tickets públicos son predeterminados (en lugar de privados)',
            'order_by_last_updated'           => 'Ordenar tickets por fecha de última actualización',
            'group_by_last_updated'           => 'Agrupar tickets por fecha de última actualización',
            'enable_autoresponder'            => 'Habilitar Mensaje de Respuesta Automática',
            'enable_important_notice_message' => 'Habilitar Mensaje de Aviso Importante',
            'updated_successfully'            => 'Configuración actualizada correctamente.',
            'ticket_tag_updated_successfully' => 'Etiqueta de ticket actualizada correctamente.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Agents
        |--------------------------------------------------------------------------
        */
        'agents'               => [
            'open_tickets'                       => 'Tickets Abiertos',
            'closed_tickets'                     => 'Tickets Cerrados',
            'last_signed_in'                     => 'Último Inicio de Sesión',
            'statistics'                         => 'Estadísticas',
            'assign_agent'                       => 'Asignar Agente',
            'edit_agent'                         => 'Editar Agente',
            'designation'                        => 'Designación',
            'bio'                                => 'Biografía',
            'description'                        => 'Primero, crea un rol desde <a href=":role_url" target="_blank">Crear Rol</a>, luego crea un administrador desde <a href=":administrator_url" target="_blank">Crear Administrador</a>. Asigna el rol creado al administrador y finalmente designa al administrador como Agente de Soporte.',
            'assigned_successfully'              => 'Agente asignado correctamente.',
            'already_assigned_or_no_change'      => 'El agente ya está asignado o no hubo cambios.',
            'never_logged_in'                    => 'Nunca ha iniciado sesión',
            'confirm_activate_text'              => '¿Realmente deseas activar estos agentes?',
            'confirm_deactivate_text'            => '¿Realmente deseas desactivar estos agentes?',
            'confirm_delete_text'                => '¿Realmente deseas liberar a estos agentes como agentes de soporte?',
            'yes_activate_it'                    => '¡Sí, activarlo!',
            'yes_deactivate_it'                  => '¡Sí, desactivarlo!',
            'yes_release_it'                     => '¡Sí, liberarlo!',
            'deactivated_successfully'           => 'Agente desactivado correctamente.',
            'updated_successfully'               => 'Agente actualizado correctamente.',
            'deleted_successfully'               => 'Usuario administrador liberado como agente de soporte correctamente.',
            'no_valid_selected_for_deactivation' => 'No se seleccionaron agentes válidos para desactivación.',
            'activate_selected'                  => 'Activar Seleccionados',
            'activated_multiple_successfully'    => ':count agentes han sido activados correctamente.',
            'deactivate_selected'                => 'Desactivar Seleccionados',
            'deactivated_multiple_successfully'  => ':count agentes han sido desactivados correctamente.',
            'delete_selected'                    => 'Liberar Seleccionados',
            'deleted_multiple_successfully'      => ':count agentes han sido liberados como agentes de soporte correctamente.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Categories
        |--------------------------------------------------------------------------
        */
        'categories'           => [
            'title_singular'                => 'Categoría de Soporte',
            'title_plural'                  => 'Categorías de Soporte',
            'name'                          => 'Nombre de la Categoría',
            'icon'                          => 'Ícono',
            'name_placeholder'              => 'p.ej., Problemas Técnicos, Consultas de Facturación',
            'slug_placeholder'              => 'p.ej., problemas-tecnicos',
            'description_placeholder'       => 'Describe brevemente la categoría',
            'add_new'                       => 'Agregar Nueva Categoría',
            'edit'                          => 'Editar Categoría',
            'details'                       => 'Detalles de la Categoría',
            'select_icon'                   => 'Seleccionar un Ícono',
            'search_icons'                  => 'Buscar íconos...',
            'created_successfully'          => '¡Categoría de soporte creada correctamente!',
            'updated_successfully'          => '¡Categoría de soporte actualizada correctamente!',
            'deleted_successfully'          => '¡Categoría de soporte eliminada correctamente!',
            'confirm_activate'              => '¿Está seguro de que desea activar las categorías seleccionadas?',
            'confirm_deactivate'            => '¿Está seguro de que desea desactivar las categorías seleccionadas?',
            'confirm_delete'                => '¿Está seguro de que desea eliminar permanentemente las categorías seleccionadas?',
            'activated_successfully'        => ':count categorías han sido activadas correctamente.',
            'deactivated_successfully'      => ':count categorías han sido desactivadas correctamente.',
            'deleted_multiple_successfully' => ':count categorías han sido eliminadas correctamente.',
            'slug_auto_generated_help'      => 'El slug se generará automáticamente desde el nombre si se deja vacío.',
            'browse_by_category'            => 'Explorar por Categoría',
            'published_on'                  => 'Publicado el',
            'related_questions'             => 'Preguntas Relacionadas',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Tickets
        |--------------------------------------------------------------------------
        */
        'tickets'              => [
            'title_singular'                        => 'Ticket de Soporte',
            'title_plural'                          => 'Tickets de Soporte',
            'create_ticket'                         => 'Crear Ticket',
            'view_ticket'                           => 'Ver Ticket',
            'created_successfully'                  => '¡Ticket de soporte creado correctamente!',
            'updated_successfully'                  => '¡Ticket de soporte actualizado correctamente!',
            'deleted_successfully'                  => '¡Ticket de soporte eliminado correctamente!',
            'status_updated_successfully'           => '¡Estado del ticket actualizado correctamente!',
            'assigned_successfully'                 => '¡Ticket asignado correctamente!',
            'tags_updated_successfully'             => '¡Etiquetas del ticket actualizadas correctamente!',
            'confirm_action'                        => 'Estás a punto de :action :length tickets.',
            'opened_successfully'                   => '¡:count tickets abiertos correctamente!',
            'closed_successfully'                   => '¡:count tickets cerrados correctamente!',
            'marked_pending_successfully'           => '¡:count tickets marcados como pendientes correctamente!',
            'assigned_multiple_successfully'        => '¡:count tickets asignados a :agent correctamente!',
            'deleted_multiple_successfully'         => '¡:count tickets eliminados correctamente!',
            'starred_successfully'                  => '¡Estado de estrella del ticket actualizado correctamente!',
            'category'                              => 'Categoría',
            'assigned_agent'                        => 'Agente Asignado',
            'last_replied'                          => 'Última Respuesta',
            'priority'                              => 'Prioridad',
            'customer_name'                         => 'Nombre del Cliente',
            'customer_email'                        => 'Correo del Cliente',
            'please_select_an_agent_for_assignment' => 'Por favor selecciona un agente para la asignación.',
            'search_placeholder'                    => 'Buscar Tickets...',
            'filter_by_status'                      => 'Filtrar por Estado',
            'filter_by_priority'                    => 'Filtrar por Prioridad',
            'assigned_to'                           => 'Asignado a :agent_name',
            'unassigned'                            => 'Sin asignar',
            'last_updated'                          => 'Actualizado hace :time',
            'no_tickets_found'                      => 'No se encontraron tickets que coincidan con tus criterios.',
            'category_help'                         => '¿Con qué categoría necesitas ayuda?',
            'subject_help'                          => 'En general, ¿sobre qué trata este ticket?',
            'subject_placeholder'                   => '¿Sobre qué trata este ticket?',
            'related_url'                           => 'URL Relacionada',
            'related_url_help'                      => 'Opcional, pero muy útil.',
            'description_help'                      => 'Por favor, sé lo más descriptivo posible sobre los detalles de este ticket.',
            'description_placeholder'               => '¿Cómo podemos ayudarte hoy?',
            'agent_help'                            => 'Opcionalmente asigna un agente a este ticket.',
            'select_agent'                          => 'Selecciona un agente',
            'file_type_not_allowed'                 => 'Tipo de archivo no permitido.',
            'file_extension_not_allowed'            => 'Extensión de archivo no permitida.',
            'file_too_large'                        => 'El archivo es demasiado grande. Máximo permitido :max.',
            'one_or_more_files_invalid'             => 'Uno o más archivos seleccionados no son válidos (tipo o tamaño). Por favor verifica e inténtalo nuevamente.',
            'max_attachments_exceeded'              => 'Puedes subir un máximo de :max archivos adjuntos.',
            'make_public'                           => 'Hacer este ticket público',
            'is_public_customer_notes'              => 'Por defecto, solo el equipo de soporte puede ver y responder tus tickets.',
            'is_public_help'                        => '<i>Un ticket público,</i> permitiría que toda la comunidad lo vea y responda. <b>Nota: no podrán ver la información ingresada en los campos "privados" anteriores.</b>',
            'status_help'                           => 'Estado actual del ticket.',
            'select_status'                         => 'Seleccionar estado',
            'priority_help'                         => 'Nivel de importancia del ticket.',
            'select_priority'                       => 'Seleccionar prioridad',
            'customer_help'                         => 'Selecciona el cliente para quien se crea este ticket.',
            'select_customer'                       => 'Seleccionar cliente',
            'private_ticket'                        => 'TICKET PRIVADO',
            'original_description'                  => 'Descripción Original del Ticket',
            'no_replies_yet'                        => 'Aún no se han agregado respuestas o notas.',
            'post_a_reply'                          => 'Publicar una Respuesta',
            'post_a_note'                           => 'Publicar una Nota',
            'reply_placeholder'                     => 'Agregar a esta conversación...',
            'private_note'                          => 'Nota Privada',
            'internal_note'                         => 'Nota Interna',
            'public_reply'                          => 'Respuesta Pública',
            'post_reply'                            => 'Publicar Respuesta',
            'post_note'                             => 'Publicar Nota',
            'ticket_details'                        => 'DETALLES DEL TICKET',
            'contact'                               => 'Contacto',
            'assigned'                              => 'ASIGNADO',
            'created'                               => 'CREADO',
            'response'                              => 'RESPUESTA',
            'public_ticket'                         => 'TICKET PÚBLICO',
            'private'                               => 'Privado',
            'public'                                => 'Público',
            'privately_reply'                       => 'Responder Privadamente',
            'add_customer_note_btn'                 => 'Agregar Nota del Cliente',
            'no_response_yet'                       => 'Aún no hay respuesta',
            'delete_ticket'                         => 'Eliminar este ticket',
            'ticket_tags'                           => 'ETIQUETAS DEL TICKET',
            'select_some_options'                   => 'Seleccionar Opciones',
            'reply_added_successfully'              => '¡Respuesta agregada correctamente!',
            'attachments'                           => 'Archivos Adjuntos',
            'needs_response'                        => 'Necesita Respuesta',
            'customer_notes'                        => 'Notas del Cliente',
            'close_ticket'                          => 'Cerrar Ticket',
            'ticket_closed_successfully'            => '¡Ticket cerrado correctamente!',
            'select_tags'                           => 'Seleccionar Etiquetas',
            'update_priority_successfully'          => '¡Prioridad del ticket actualizada correctamente!',
            'category_updated_successfully'         => '¡Categoría del ticket actualizada correctamente!',
            'replied_privately'                     => 'Respondido Privadamente',
            'privacy_policy_note'                   => 'Lee nuestra <a href=":privacy_policy_url" target="_blank">Política de Privacidad</a> y descubre cómo manejamos tus datos personales',
            'all_cleared'                           => 'Todo borrado.',

            'statuses'   => [
                'pending'    => 'Pendiente',
                'open'       => 'Abierto',
                'resolved'   => 'Resuelto',
                'closed'     => 'Cerrado',
                'replied'    => 'Respondido (-por cliente)',
                'starred'    => 'Destacado',
                'unassigned' => 'Sin asignar',
            ],
            'priorities' => [
                'low'    => 'Baja',
                'medium' => 'Media',
                'high'   => 'Alta',
                'urgent' => 'Urgente',
            ],
            'filters'    => [
                'title'               => 'Filtros',
                'all_tickets'         => 'Todos los Tickets',
                'my_tickets'          => 'Mis Tickets',
                'unassigned_tickets'  => 'Tickets Sin Asignar',
                'starred_tickets'     => 'Tickets Destacados',
                'categories'          => 'Categorías',
                'no_categories_found' => 'No se encontraron Categorías',
                'agents'              => 'Agentes',
                'no_agents_found'     => 'No se encontraron Agentes',
            ],
            'stats'      => [
                'title'              => 'Estadísticas Rápidas',
                'total_tickets'      => 'Total de Tickets',
                'open_tickets'       => 'Tickets Abiertos',
                'closed_last_7_days' => 'Cerrados (Últimos 7 Días)',
                'closed_older_than'  => 'Más de 7 Días',
                'closed_other'       => 'Dentro de los Últimos 7 Días (Otros)',
                'closed_2days'       => 'Actualizado hace 2 Días',
                'updated_today'      => 'Actualizado Hoy',
                'no_action_needed'   => 'No se requiere acción',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Replies
        |--------------------------------------------------------------------------
        */
        'replies'              => [
            'added_successfully'        => '¡Respuesta agregada correctamente!',
            'message'                   => 'Mensaje de Respuesta',
            'add_reply'                 => 'Agregar Respuesta',
            'your_reply'                => 'Tu Respuesta',
            'title_plural'              => 'Respuestas',
            'edited'                    => 'Editado',
            'note_updated_successfully' => 'Nota actualizada correctamente.',
            'fetched_successfully'      => 'Respuesta obtenida correctamente.',
            'not_found'                 => 'La respuesta no pertenece al ticket especificado.',
            'updated_successfully'      => 'Respuesta actualizada correctamente.',
            'deleted_successfully'      => 'Respuesta eliminada correctamente.',
            'delete_failed'             => 'No se pudo eliminar la respuesta.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Attachments
        |--------------------------------------------------------------------------
        */
        'attachments'          => [
            'title_plural'         => 'Archivos Adjuntos',
            'add_attachments'      => 'Agregar Archivos Adjuntos',
            'not_found'            => 'El archivo adjunto no pertenece al ticket especificado.',
            'deleted_successfully' => '¡Archivo adjunto eliminado correctamente!',
            'delete_failed'        => 'No se pudo eliminar el archivo adjunto.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Tags
        |--------------------------------------------------------------------------
        */
        'tags'                 => [
            'title_singular'                => 'Etiqueta de Ticket',
            'title_plural'                  => 'Etiquetas de Tickets',
            'name'                          => 'Nombre de la Etiqueta',
            'name_placeholder'              => 'Ingrese el nombre de la etiqueta',
            'description'                   => 'Descripción de la Etiqueta',
            'color'                         => 'Color',
            'add_new'                       => 'Agregar Nueva Etiqueta',
            'edit'                          => 'Editar Etiqueta',
            'created_successfully'          => '¡Etiqueta creada correctamente!',
            'updated_successfully'          => '¡Etiqueta actualizada correctamente!',
            'deleted_successfully'          => '¡Etiqueta eliminada correctamente!',
            'deleted_multiple_successfully' => '¡:count etiquetas eliminadas correctamente!',
        ],
        /*
        |--------------------------------------------------------------------------
        | Support Articles
        |--------------------------------------------------------------------------
        */
        'articles'             => [
            'title_singular'             => 'Artículo de Soporte',
            'title_plural'               => 'Artículos de Soporte',
            'create'                     => 'Crear Artículo de Soporte',
            'edit'                       => 'Editar Artículo de Soporte',
            'published_status'           => 'Estado de Publicación',
            'featured_status'            => 'Estado Destacado',
            'draft'                      => 'Borrador',
            'published'                  => 'Publicado',
            'featured'                   => 'Destacado',
            'views'                      => 'Vistas',
            'confirm_delete_text'        => '¡No podrás recuperar este artículo de soporte!',
            'deleted_successfully'       => '¡El artículo de soporte se eliminó correctamente!',
            'delete_failed'              => 'No se pudo eliminar el artículo de soporte.',
            'created_successfully'       => '¡Artículo de soporte creado correctamente!',
            'updated_successfully'       => '¡Artículo de soporte actualizado correctamente!',
            'update_failed'              => 'No se pudo actualizar el artículo de soporte.',
            'published_successfully'     => 'Artículo publicado correctamente.',
            'unpublished_successfully'   => 'Artículo despublicado correctamente.',
            'featured_successfully'      => 'Artículo destacado correctamente.',
            'unfeatured_successfully'    => 'Artículo desmarcado como destacado correctamente.',
            'slug_help'                  => 'Déjalo vacío para generar automáticamente a partir del título. Debe ser único.',
            'is_published_help'          => 'Déjalo sin marcar para guardar como borrador',
            'confirm_publish'            => '¿Estás seguro de que deseas publicar los artículos seleccionados?',
            'confirm_unpublish'          => '¿Estás seguro de que deseas despublicar los artículos seleccionados?',
            'confirm_feature'            => '¿Estás seguro de que deseas destacar los artículos seleccionados?',
            'confirm_unfeature'          => '¿Estás seguro de que deseas quitar el destacado de los artículos seleccionados?',
            'confirm_delete'             => '¿Estás seguro de que deseas eliminar permanentemente los artículos seleccionados?',
            'batch_action_success'       => ':count artículos han sido :action correctamente.',
            'batch_action_failed'        => 'La acción en lote falló.',
            'batch_action_failed_no_ids' => 'La acción en lote falló. No se proporcionaron IDs.',
            'help_text'                  => '¡Bienvenido a Soporte! Tenemos :count artículos para ayudarte',
            'search_placeholder'         => 'Busca nuestros artículos de ayuda o preguntas frecuentes...',
            'read_article'               => 'Leer Artículo',
            'featured_articles'          => 'Artículos Destacados',
            'most_viewed_articles'       => 'Artículos Más Vistos',
            'question'                   => 'Pregunta',
            'last_updated_on'            => 'Última Actualización',
        ],


        /*
        |--------------------------------------------------------------------------
        | Batch Actions - General messages for all batch actions
        |--------------------------------------------------------------------------
        */
        'batch_actions'        => [
            'select_action'                         => 'Seleccionar Acción en Lote',
            'select_action_warning'                 => 'Por favor selecciona una acción en lote.',
            'select_items_warning'                  => 'Por favor selecciona al menos un elemento.',
            'confirm_title'                         => '¿Estás seguro?',
            'confirm_text'                          => 'Estás a punto de realizar una acción en lote: :action en los elementos seleccionados.',
            'you_will_be_able_to_revert_this_later' => 'No podrás revertir esto más tarde.',
            'bulk_actions'                          => 'Acciones en Lote',
        ],


        /*
        |--------------------------------------------------------------------------
        | Support Analytics
        |--------------------------------------------------------------------------
        */
        'analytics'            => [
            'new_tickets'              => 'Nuevos Tickets',
            'agent_responses'          => 'Respuestas de Agentes',
            'resolved_tickets'         => 'Tickets Resueltos',
            'analytics_overview'       => 'Resumen de Analíticas',
            'all_categories'           => 'Todas las Categorías',
            'all_agents'               => 'Todos los Agentes',
            'apply_filters'            => 'Aplicar Filtros',
            'no_change_from_yesterday' => 'Sin cambios desde ayer',
            'from_yesterday'           => 'Desde ayer',
            'agent_replies'            => 'Respuestas de Agentes',
            'updated'                  => '¡Datos de analíticas actualizados correctamente!',
            'date_range'               => 'Rango de Fechas',
            'select_date_range'        => 'Seleccionar Rango de Fechas',
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
                'view_any'     => 'No tienes permiso para ver las categorías de soporte.',
                'view'         => 'No tienes permiso para ver esta categoría de soporte.',
                'create'       => 'No tienes permiso para crear categorías de soporte.',
                'update'       => 'No tienes permiso para actualizar esta categoría de soporte.',
                'delete'       => 'No tienes permiso para eliminar esta categoría de soporte.',
                'delete_any'   => 'No tienes permiso para eliminar múltiples categorías de soporte.',
                'batch_action' => 'No tienes permiso para realizar acciones en lote sobre categorías de soporte.',
            ],
            'support_articles'   => [
                'view_any'     => 'No tienes permiso para ver artículos de soporte.',
                'view'         => 'No tienes permiso para ver este artículo de soporte.',
                'create'       => 'No tienes permiso para crear artículos de soporte.',
                'update'       => 'No tienes permiso para actualizar este artículo de soporte.',
                'delete'       => 'No tienes permiso para eliminar este artículo de soporte.',
                'delete_any'   => 'No tienes permiso para eliminar múltiples artículos de soporte.',
                'batch_action' => 'No tienes permiso para realizar acciones en lote sobre artículos de soporte.',
                'publish'      => 'No tienes permiso para publicar o despublicar este artículo de soporte.',
                'feature'      => 'No tienes permiso para destacar o quitar el destacado de este artículo de soporte.',
            ],
            'support_tickets'    => [
                'view_any'      => 'No tienes permiso para ver los tickets de soporte.',
                'view'          => 'No tienes permiso para ver este ticket de soporte.',
                'create'        => 'No tienes permiso para crear tickets de soporte.',
                'update'        => 'No tienes permiso para actualizar este ticket.',
                'delete'        => 'No tienes permiso para eliminar este ticket.',
                'reply'         => 'No tienes permiso para responder a este ticket o está cerrado.',
                'update_status' => 'No tienes permiso para actualizar el estado de este ticket.',
                'assign'        => 'No tienes permiso para asignar este ticket.',
                'manage_tags'   => 'No tienes permiso para administrar etiquetas de este ticket.',
                'delete_any'    => 'No tienes permiso para eliminar múltiples tickets de soporte.',
                'batch_action'  => 'No tienes permiso para realizar acciones en lote sobre tickets de soporte.',
            ],
            'ticket_replies'     => [
                'update'     => 'No tienes permiso para actualizar esta respuesta, o el tiempo de edición ha expirado.',
                'delete'     => 'No tienes permiso para eliminar esta respuesta.',
                'delete_any' => 'No tienes permiso para eliminar múltiples respuestas de tickets.',
            ],
            'ticket_attachments' => [
                'view'       => 'No tienes permiso para ver este adjunto.',
                'delete'     => 'No tienes permiso para eliminar este adjunto.',
                'delete_any' => 'No tienes permiso para eliminar múltiples adjuntos de tickets.',
            ],
        ],

    ];
