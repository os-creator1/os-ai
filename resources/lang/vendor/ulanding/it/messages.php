<?php

    return [

        // General messages
        'something_went_wrong' => 'Qualcosa è andato storto. Riprova più tardi.',
        'permission_denied'    => 'Non hai il permesso di eseguire questa azione.',
        'operation_successful' => 'Operazione completata con successo!',

        // Global Labels
        'last_modified'        => 'Ultima Modifica',
        'description'          => 'Descrizione',
        'slug'                 => 'Slug',
        'is_active'            => 'È Attivo?',
        'add_new'              => 'Aggiungi Nuovo',
        'edit'                 => 'Modifica',
        'back_to_list'         => 'Torna alla Lista',
        'select_category'      => '-- Seleziona Categoria --',
        'uncategorized'        => 'Non Categorizzato',
        'unknown_user'         => 'Utente Sconosciuto',
        'confirm_delete_title' => 'Sei sicuro?',
        'you'                  => 'Tu',
        'updated'              => 'Aggiornato',

        // Buttons
        'buttons'              => [
            'open'       => 'Apri',
            'reply'      => 'Rispondi',
            'publish'    => 'Pubblica',
            'unpublish'  => 'Rimuovi Pubblicazione',
            'feature'    => 'Metti in Evidenza',
            'unfeature'  => 'Rimuovi Evidenziazione',
            'deactivate' => 'Disattiva',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Settings
        |--------------------------------------------------------------------------
        */
        'settings'             => [
            'support_desk_name'               => 'Nome del Supporto',
            'support_desk_name_help'          => 'Usato in tutto il sistema di supporto, nelle email, ecc.',
            'support_desk_email'              => 'Email del Supporto',
            'support_desk_email_help'         => 'Usata solo per inviare aggiornamenti importanti.',
            'title'                           => 'Impostazioni Supporto',
            'ticket_settings'                 => 'Impostazioni Ticket',
            'ticket_tags'                     => 'Tag Ticket',
            'privacy_settings'                => 'Impostazioni Privacy',
            'important_notice_messages'       => 'Messaggi di Avviso Importanti',
            'auto_reassign'                   => 'Riassegna automaticamente i ticket all’agente che risponde',
            'filter_by_category'              => 'Filtra l’elenco degli articoli per categoria nella casella di risposta del ticket',
            'disable_public_tickets'          => 'Disabilita Ticket Pubblici <span class="text-muted small">(I ticket pubblici esistenti non saranno modificati)</span>',
            'public_tickets_default'          => 'I ticket pubblici sono predefiniti (invece dei privati)',
            'order_by_last_updated'           => 'Ordina i ticket per data di ultima modifica in ordine crescente',
            'group_by_last_updated'           => 'Raggruppa i ticket per data dell’ultimo aggiornamento',
            'enable_autoresponder'            => 'Abilita Messaggio Autoresponder',
            'enable_important_notice_message' => 'Abilita Messaggio di Avviso Importante',
            'updated_successfully'            => 'Impostazioni aggiornate con successo.',
            'ticket_tag_updated_successfully' => 'Tag del ticket aggiornato con successo.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Agents
        |--------------------------------------------------------------------------
        */
        'agents'               => [
            'open_tickets'                       => 'Ticket Aperti',
            'closed_tickets'                     => 'Ticket Chiusi',
            'last_signed_in'                     => 'Ultimo Accesso',
            'statistics'                         => 'Statistiche',
            'assign_agent'                       => 'Assegna Agente',
            'edit_agent'                         => 'Modifica Agente',
            'designation'                        => 'Ruolo',
            'bio'                                => 'Bio',
            'description'                        => 'Prima, crea un ruolo da <a href=":role_url" target="_blank">Crea Ruolo</a>, poi crea un amministratore da <a href=":administrator_url" target="_blank">Crea Amministratore</a>. Assegna il ruolo creato all’amministratore, quindi designa l’amministratore come Agente di Supporto.',
            'assigned_successfully'              => 'Agente assegnato con successo.',
            'already_assigned_or_no_change'      => 'L’agente è già assegnato o non ci sono modifiche.',
            'never_logged_in'                    => 'Mai Loggato',
            'confirm_activate_text'              => 'Vuoi davvero attivare questi agenti?',
            'confirm_deactivate_text'            => 'Vuoi davvero disattivare questi agenti?',
            'confirm_delete_text'                => 'Vuoi davvero rimuovere questi agenti dal supporto?',
            'yes_activate_it'                    => 'Sì, attivalo!',
            'yes_deactivate_it'                  => 'Sì, disattivalo!',
            'yes_release_it'                     => 'Sì, rimuovilo!',
            'deactivated_successfully'           => 'Agente disattivato con successo.',
            'updated_successfully'               => 'Agente aggiornato con successo.',
            'deleted_successfully'               => 'Agente rimosso dal supporto con successo.',
            'no_valid_selected_for_deactivation' => 'Nessun agente valido selezionato per la disattivazione.',
            'activate_selected'                  => 'Attiva Selezionati',
            'activated_multiple_successfully'    => ':count agenti sono stati attivati con successo.',
            'deactivate_selected'                => 'Disattiva Selezionati',
            'deactivated_multiple_successfully'  => ':count agenti sono stati disattivati con successo.',
            'delete_selected'                    => 'Rimuovi Selezionati',
            'deleted_multiple_successfully'      => ':count agenti sono stati rimossi dal supporto con successo.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Categories
        |--------------------------------------------------------------------------
        */
        'categories'           => [
            'title_singular'                => 'Categoria di Supporto',
            'title_plural'                  => 'Categorie di Supporto',
            'name'                          => 'Nome Categoria',
            'icon'                          => 'Icona',
            'name_placeholder'              => 'es. Problemi Tecnici, Fatturazione',
            'slug_placeholder'              => 'es. problemi-tecnici',
            'description_placeholder'       => 'Descrivi brevemente la categoria',
            'add_new'                       => 'Aggiungi Nuova Categoria',
            'edit'                          => 'Modifica Categoria',
            'details'                       => 'Dettagli Categoria',
            'select_icon'                   => 'Seleziona un’Icona',
            'search_icons'                  => 'Cerca icone...',
            'created_successfully'          => 'Categoria di supporto creata con successo!',
            'updated_successfully'          => 'Categoria di supporto aggiornata con successo!',
            'deleted_successfully'          => 'Categoria di supporto eliminata con successo!',
            'confirm_activate'              => 'Sei sicuro di voler attivare le categorie selezionate?',
            'confirm_deactivate'            => 'Sei sicuro di voler disattivare le categorie selezionate?',
            'confirm_delete'                => 'Sei sicuro di voler eliminare definitivamente le categorie selezionate?',
            'activated_successfully'        => ':count categorie attivate con successo.',
            'deactivated_successfully'      => ':count categorie disattivate con successo.',
            'deleted_multiple_successfully' => ':count categorie eliminate con successo.',
            'slug_auto_generated_help'      => 'Lo slug sarà generato automaticamente dal nome se lasciato vuoto.',
            'browse_by_category'            => 'Sfoglia per Categoria',
            'published_on'                  => 'Pubblicato il',
            'related_questions'             => 'Domande Correlate',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Tickets
        |--------------------------------------------------------------------------
        */
        'tickets'              => [
            'title_singular'                        => 'Ticket di Supporto',
            'title_plural'                          => 'Ticket di Supporto',
            'create_ticket'                         => 'Crea Ticket',
            'view_ticket'                           => 'Visualizza Ticket',
            'created_successfully'                  => 'Ticket creato con successo!',
            'updated_successfully'                  => 'Ticket aggiornato con successo!',
            'deleted_successfully'                  => 'Ticket eliminato con successo!',
            'status_updated_successfully'           => 'Stato del ticket aggiornato con successo!',
            'assigned_successfully'                 => 'Ticket assegnato con successo!',
            'tags_updated_successfully'             => 'Tag del ticket aggiornati con successo!',
            'confirm_action'                        => 'Stai per :action :length ticket.',
            'opened_successfully'                   => ':count ticket aperti con successo!',
            'closed_successfully'                   => ':count ticket chiusi con successo!',
            'marked_pending_successfully'           => ':count ticket contrassegnati come in sospeso con successo!',
            'assigned_multiple_successfully'        => ':count ticket assegnati a :agent con successo!',
            'deleted_multiple_successfully'         => ':count ticket eliminati con successo!',
            'starred_successfully'                  => 'Stato dei ticket contrassegnati aggiornato con successo!',
            'category'                              => 'Categoria',
            'assigned_agent'                        => 'Agente Assegnato',
            'last_replied'                          => 'Ultima Risposta',
            'priority'                              => 'Priorità',
            'customer_name'                         => 'Nome Cliente',
            'customer_email'                        => 'Email Cliente',
            'please_select_an_agent_for_assignment' => 'Seleziona un agente per l’assegnazione.',
            'search_placeholder'                    => 'Cerca Ticket...',
            'filter_by_status'                      => 'Filtra per Stato',
            'filter_by_priority'                    => 'Filtra per Priorità',
            'assigned_to'                           => 'Assegnato a :agent_name',
            'unassigned'                            => 'Non Assegnato',
            'last_updated'                          => 'Aggiornato :time fa',
            'no_tickets_found'                      => 'Nessun ticket trovato.',
            'category_help'                         => 'Con quale categoria hai bisogno di aiuto?',
            'subject_help'                          => 'Di cosa tratta in generale questo ticket?',
            'subject_placeholder'                   => 'Di cosa tratta questo ticket?',
            'related_url'                           => 'URL Correlato',
            'related_url_help'                      => 'Opzionale, ma utile.',
            'description_help'                      => 'Sii il più descrittivo possibile riguardo i dettagli di questo ticket.',
            'description_placeholder'               => 'Come possiamo aiutarti oggi?',
            'agent_help'                            => 'Assegna opzionalmente un agente a questo ticket.',
            'select_agent'                          => 'Seleziona un agente',
            'file_type_not_allowed'                 => 'Tipo di file non consentito.',
            'file_extension_not_allowed'            => 'Estensione del file non consentita.',
            'file_too_large'                        => 'File troppo grande. Max :max_size consentiti.',
            'one_or_more_files_invalid'             => 'Uno o più file selezionati non sono validi (tipo o dimensione). Controlla e riprova.',
            'max_attachments_exceeded'              => 'Puoi caricare un massimo di :max allegati.',
            'make_public'                           => 'Rendi questo ticket pubblico',
            'is_public_customer_notes'              => 'Di default, solo il team di supporto può vedere e rispondere ai tuoi ticket.',
            'is_public_help'                        => '<i>Un ticket pubblico</i>, però, permette a tutta la community di visualizzare e rispondere. <b>Nota: non possono vedere le informazioni inserite nei campi "privati".</b>',
            'status_help'                           => 'Stato attuale del ticket.',
            'select_status'                         => 'Seleziona Stato',
            'priority_help'                         => 'Livello di importanza del ticket.',
            'select_priority'                       => 'Seleziona Priorità',
            'customer_help'                         => 'Seleziona il cliente per cui viene creato questo ticket.',
            'select_customer'                       => 'Seleziona Cliente',
            'private_ticket'                        => 'TICKET PRIVATO',
            'original_description'                  => 'Descrizione Originale',
            'no_replies_yet'                        => 'Nessuna risposta o nota aggiunta.',
            'post_a_reply'                          => 'Pubblica una Risposta',
            'post_a_note'                           => 'Pubblica una Nota',
            'reply_placeholder'                     => 'Aggiungi a questa conversazione...',
            'private_note'                          => 'Nota Privata',
            'internal_note'                         => 'Nota Interna',
            'public_reply'                          => 'Risposta Pubblica',
            'post_reply'                            => 'Invia Risposta',
            'post_note'                             => 'Invia Nota',
            'ticket_details'                        => 'DETTAGLI TICKET',
            'contact'                               => 'Contatto',
            'assigned'                              => 'ASSEGNATO',
            'created'                               => 'CREATO',
            'response'                              => 'RISPOSTA',
            'public_ticket'                         => 'TICKET PUBBLICO',
            'private'                               => 'Privato',
            'public'                                => 'Pubblico',
            'privately_reply'                       => 'Rispondi in Privato',
            'add_customer_note_btn'                 => 'Aggiungi Nota Cliente',
            'no_response_yet'                       => 'Nessuna risposta ancora',
            'delete_ticket'                         => 'Elimina questo ticket',
            'ticket_tags'                           => 'TAG DEL TICKET',
            'select_some_options'                   => 'Seleziona Opzioni',
            'reply_added_successfully'              => 'Risposta aggiunta con successo!',
            'attachments'                           => 'Allegati',
            'needs_response'                        => 'Richiede Risposta',
            'customer_notes'                        => 'Note Cliente',
            'close_ticket'                          => 'Chiudi Ticket',
            'ticket_closed_successfully'            => 'Ticket chiuso con successo!',
            'select_tags'                           => 'Seleziona Tag',
            'update_priority_successfully'          => 'Priorità del ticket aggiornata con successo!',
            'category_updated_successfully'         => 'Categoria del ticket aggiornata con successo!',
            'replied_privately'                     => 'Risposto in Privato',
            'privacy_policy_note'                   => 'Leggi la nostra <a href=":privacy_policy_url" target="_blank">Privacy Policy</a> e scopri come gestiamo i tuoi dati personali',
            'all_cleared'                           => 'Tutto cancellato.',

            'statuses' => [
                'pending'    => 'In Attesa',
                'open'       => 'Aperto',
                'resolved'   => 'Risolto',
                'closed'     => 'Chiuso',
                'replied'    => 'Risposto (-dal cliente)',
                'starred'    => 'Contrassegnato',
                'unassigned' => 'Non Assegnato',
            ],

            'priorities' => [
                'low'    => 'Bassa',
                'medium' => 'Media',
                'high'   => 'Alta',
                'urgent' => 'Urgente',
            ],

            'filters' => [
                'title'               => 'Filtri',
                'all_tickets'         => 'Tutti i Ticket',
                'my_tickets'          => 'I miei Ticket',
                'unassigned_tickets'  => 'Ticket Non Assegnati',
                'starred_tickets'     => 'Ticket Contrassegnati',
                'categories'          => 'Categorie',
                'no_categories_found' => 'Nessuna Categoria Trovata',
                'agents'              => 'Agenti',
                'no_agents_found'     => 'Nessun Agente Trovato',
            ],

            'stats' => [
                'title'              => 'Statistiche Veloci',
                'total_tickets'      => 'Totale Ticket',
                'open_tickets'       => 'Ticket Aperti',
                'closed_last_7_days' => 'Chiusi (Ultimi 7 Giorni)',
                'closed_older_than'  => 'Più Vecchi di 7 Giorni',
                'closed_other'       => 'Ultimi 7 Giorni (Altro)',
                'closed_2days'       => 'Aggiornati 2 Giorni Fa',
                'updated_today'      => 'Aggiornati Oggi',
                'no_action_needed'   => 'Nessuna Azione Necessaria',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Replies
        |--------------------------------------------------------------------------
        */
        'replies'              => [
            'added_successfully'        => 'Risposta aggiunta con successo!',
            'message'                   => 'Messaggio di Risposta',
            'add_reply'                 => 'Aggiungi Risposta',
            'your_reply'                => 'La Tua Risposta',
            'title_plural'              => 'Risposte',
            'edited'                    => 'Modificato',
            'note_updated_successfully' => 'Nota aggiornata con successo.',
            'fetched_successfully'      => 'Risposta recuperata con successo.',
            'not_found'                 => 'La risposta non appartiene al ticket specificato.',
            'updated_successfully'      => 'Risposta aggiornata con successo.',
            'deleted_successfully'      => 'Risposta eliminata con successo.',
            'delete_failed'             => 'Impossibile eliminare la risposta.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Attachments
        |--------------------------------------------------------------------------
        */
        'attachments'          => [
            'title_plural'         => 'Allegati',
            'add_attachments'      => 'Aggiungi Allegati',
            'not_found'            => 'L’allegato non appartiene al ticket specificato.',
            'deleted_successfully' => 'Allegato eliminato con successo!',
            'delete_failed'        => 'Impossibile eliminare l’allegato.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Tags
        |--------------------------------------------------------------------------
        */
        'tags'                 => [
            'title_singular'                => 'Tag Ticket',
            'title_plural'                  => 'Tag Ticket',
            'name'                          => 'Nome Tag',
            'name_placeholder'              => 'Inserisci il nome del tag',
            'description'                   => 'Descrizione Tag',
            'color'                         => 'Colore',
            'add_new'                       => 'Aggiungi Nuovo Tag',
            'edit'                          => 'Modifica Tag',
            'created_successfully'          => 'Tag creato con successo!',
            'updated_successfully'          => 'Tag aggiornato con successo!',
            'deleted_successfully'          => 'Tag eliminato con successo!',
            'deleted_multiple_successfully' => ':count tag eliminati con successo!',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Articles
        |--------------------------------------------------------------------------
        */
        'articles'             => [
            'title_singular'             => 'Articolo di Supporto',
            'title_plural'               => 'Articoli di Supporto',
            'create'                     => 'Crea Articolo di Supporto',
            'edit'                       => 'Modifica Articolo di Supporto',
            'published_status'           => 'Stato di Pubblicazione',
            'featured_status'            => 'Stato in Evidenza',
            'draft'                      => 'Bozza',
            'published'                  => 'Pubblicato',
            'featured'                   => 'In Evidenza',
            'views'                      => 'Visualizzazioni',
            'confirm_delete_text'        => 'Non potrai recuperare questo articolo di supporto!',
            'deleted_successfully'       => 'Articolo di supporto eliminato con successo.',
            'delete_failed'              => 'Impossibile eliminare l’articolo di supporto.',
            'created_successfully'       => 'Articolo di supporto creato con successo!',
            'updated_successfully'       => 'Articolo di supporto aggiornato con successo!',
            'update_failed'              => 'Impossibile aggiornare l’articolo di supporto.',
            'published_successfully'     => 'Articolo pubblicato con successo.',
            'unpublished_successfully'   => 'Articolo rimosso dalla pubblicazione con successo.',
            'featured_successfully'      => 'Articolo messo in evidenza con successo.',
            'unfeatured_successfully'    => 'Articolo rimosso dall’evidenza con successo.',
            'slug_help'                  => 'Lascia vuoto per generare automaticamente dal titolo. Deve essere unico.',
            'is_published_help'          => 'Lascia deselezionato per salvare come bozza',
            'confirm_publish'            => 'Sei sicuro di voler pubblicare gli articoli selezionati?',
            'confirm_unpublish'          => 'Sei sicuro di voler rimuovere dalla pubblicazione gli articoli selezionati?',
            'confirm_feature'            => 'Sei sicuro di voler mettere in evidenza gli articoli selezionati?',
            'confirm_unfeature'          => 'Sei sicuro di voler rimuovere dall’evidenza gli articoli selezionati?',
            'confirm_delete'             => 'Sei sicuro di voler eliminare definitivamente gli articoli selezionati?',
            'batch_action_success'       => ':count articoli sono stati :action con successo.',
            'batch_action_failed'        => 'Azione di gruppo fallita.',
            'batch_action_failed_no_ids' => 'Azione di gruppo fallita. Nessun ID fornito.',
            'help_text'                  => 'Benvenuto nel Supporto! Abbiamo :count articoli per aiutarti',
            'search_placeholder'         => 'Cerca nei nostri articoli di supporto o FAQ...',
            'read_article'               => 'Leggi Articolo',
            'featured_articles'          => 'Articoli in Evidenza',
            'most_viewed_articles'       => 'Articoli Più Visti',
            'question'                   => 'Domanda',
            'last_updated_on'            => 'Ultimo Aggiornamento',
        ],

        /*
        |--------------------------------------------------------------------------
        | Batch Actions - General messages for all batch actions
        |--------------------------------------------------------------------------
        */
        'batch_actions'        => [
            'select_action'                         => 'Seleziona Azione di Gruppo',
            'select_action_warning'                 => 'Seleziona un’azione di gruppo.',
            'select_items_warning'                  => 'Seleziona almeno un elemento.',
            'confirm_title'                         => 'Sei sicuro?',
            'confirm_text'                          => 'Stai per eseguire un’azione di gruppo: :action sugli elementi selezionati.',
            'you_will_be_able_to_revert_this_later' => 'Non potrai annullare questa operazione in seguito.',
            'bulk_actions'                          => 'Azioni di Gruppo',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Analytics
        |--------------------------------------------------------------------------
        */
        'analytics'            => [
            'new_tickets'              => 'Nuovi Ticket',
            'agent_responses'          => 'Risposte Agente',
            'resolved_tickets'         => 'Ticket Risolti',
            'analytics_overview'       => 'Panoramica Analitica',
            'all_categories'           => 'Tutte le Categorie',
            'all_agents'               => 'Tutti gli Agenti',
            'apply_filters'            => 'Applica Filtri',
            'no_change_from_yesterday' => 'Nessuna variazione rispetto a ieri',
            'from_yesterday'           => 'Rispetto a ieri',
            'agent_replies'            => 'Risposte Agente',
            'updated'                  => 'Dati analitici aggiornati con successo!',
            'date_range'               => 'Intervallo di Date',
            'select_date_range'        => 'Seleziona Intervallo di Date',
            'agent'                    => 'Agente',
        ],

        /*
        |--------------------------------------------------------------------------
        | Policy Denial Messages
        |--------------------------------------------------------------------------
        */
        'policies'             => [
            'support_categories' => [
                'view_any'     => 'Non hai il permesso di visualizzare le categorie di supporto.',
                'view'         => 'Non hai il permesso di visualizzare questa categoria di supporto.',
                'create'       => 'Non hai il permesso di creare categorie di supporto.',
                'update'       => 'Non hai il permesso di aggiornare questa categoria di supporto.',
                'delete'       => 'Non hai il permesso di eliminare questa categoria di supporto.',
                'delete_any'   => 'Non hai il permesso di eliminare più categorie di supporto.',
                'batch_action' => 'Non hai il permesso di eseguire azioni di gruppo sulle categorie di supporto.',
            ],
            'support_articles'   => [
                'view_any'     => 'Non hai il permesso di visualizzare gli articoli di supporto.',
                'view'         => 'Non hai il permesso di visualizzare questo articolo di supporto.',
                'create'       => 'Non hai il permesso di creare articoli di supporto.',
                'update'       => 'Non hai il permesso di aggiornare questo articolo di supporto.',
                'delete'       => 'Non hai il permesso di eliminare questo articolo di supporto.',
                'delete_any'   => 'Non hai il permesso di eliminare più articoli di supporto.',
                'batch_action' => 'Non hai il permesso di eseguire azioni di gruppo sugli articoli di supporto.',
                'publish'      => 'Non hai il permesso di pubblicare o rimuovere la pubblicazione di questo articolo.',
                'feature'      => 'Non hai il permesso di mettere in evidenza o rimuovere dall’evidenza questo articolo.',
            ],
            'support_tickets'    => [
                'view_any'      => 'Non hai il permesso di visualizzare i ticket di supporto.',
                'view'          => 'Non hai il permesso di visualizzare questo ticket di supporto.',
                'create'        => 'Non hai il permesso di creare ticket di supporto.',
                'update'        => 'Non hai il permesso di aggiornare questo ticket.',
                'delete'        => 'Non hai il permesso di eliminare questo ticket.',
                'reply'         => 'Non hai il permesso di rispondere a questo ticket o è chiuso.',
                'update_status' => 'Non hai il permesso di aggiornare lo stato di questo ticket.',
                'assign'        => 'Non hai il permesso di assegnare questo ticket.',
                'manage_tags'   => 'Non hai il permesso di gestire i tag di questo ticket.',
                'delete_any'    => 'Non hai il permesso di eliminare più ticket di supporto.',
                'batch_action'  => 'Non hai il permesso di eseguire azioni di gruppo sui ticket di supporto.',
            ],
            'ticket_replies'     => [
                'update'     => 'Non hai il permesso di aggiornare questa risposta o il tempo di modifica è scaduto.',
                'delete'     => 'Non hai il permesso di eliminare questa risposta.',
                'delete_any' => 'Non hai il permesso di eliminare più risposte dei ticket.',
            ],
            'ticket_attachments' => [
                'view'       => 'Non hai il permesso di visualizzare questo allegato.',
                'delete'     => 'Non hai il permesso di eliminare questo allegato.',
                'delete_any' => 'Non hai il permesso di eliminare più allegati dei ticket.',
            ],
        ],

    ];
