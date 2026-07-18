<?php

    return [
// General messages
        'something_went_wrong' => 'Prišlo je do napake. Poskusite znova kasneje.',
        'permission_denied'    => 'Nimate dovoljenja za izvedbo te akcije.',
        'operation_successful' => 'Operacija je bila uspešno zaključena!',

// Global Labels
        'last_modified'        => 'Zadnja sprememba',
        'description'          => 'Opis',
        'slug'                 => 'Slug',
        'is_active'            => 'Aktivno?',
        'add_new'              => 'Dodaj novo',
        'edit'                 => 'Uredi',
        'back_to_list'         => 'Nazaj na seznam',
        'select_category'      => '-- Izberi kategorijo --',
        'uncategorized'        => 'Brez kategorije',
        'unknown_user'         => 'Neznan uporabnik',
        'confirm_delete_title' => 'Ali ste prepričani?',
        'you'                  => 'Vi',
        'updated'              => 'Posodobljeno',

// Buttons
        'buttons'              => [
            'open'       => 'Odpri',
            'reply'      => 'Odgovori',
            'publish'    => 'Objavi',
            'unpublish'  => 'Odstrani objavo',
            'feature'    => 'Poudari',
            'unfeature'  => 'Odstrani poudarek',
            'deactivate' => 'Onemogoči',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Settings
        |--------------------------------------------------------------------------
        */
        'settings'             => [
            'support_desk_name'               => 'Ime podporne pisarne',
            'support_desk_name_help'          => 'Uporablja se po celotnem podpornih sistemu, v e-poštnih sporočilih itd.',
            'support_desk_email'              => 'E-pošta podporne pisarne',
            'support_desk_email_help'         => 'Uporablja se samo za pošiljanje pomembnih obvestil.',
            'title'                           => 'Nastavitve podpore',
            'ticket_settings'                 => 'Nastavitve kartic',
            'ticket_tags'                     => 'Oznake kartic',
            'privacy_settings'                => 'Nastavitve zasebnosti',
            'important_notice_messages'       => 'Pomembna obvestila',
            'auto_reassign'                   => 'Samodejno prerazporedi kartice podpornega agentu',
            'filter_by_category'              => 'Filtriraj seznam člankov po kategoriji v polju odgovora na kartico',
            'disable_public_tickets'          => 'Onemogoči javne kartice <span class="text-muted small">(Trenutne javne kartice ne bodo vplivale)</span>',
            'public_tickets_default'          => 'Javne kartice so privzete (namesto zasebnih)',
            'order_by_last_updated'           => 'Razvrsti kartice po datumu zadnje posodobitve naraščajoče',
            'group_by_last_updated'           => 'Združi kartice po datumu zadnje posodobitve',
            'enable_autoresponder'            => 'Omogoči samodejni odgovor',
            'enable_important_notice_message' => 'Omogoči pomembna obvestila',
            'updated_successfully'            => 'Nastavitve so bile uspešno posodobljene.',
            'ticket_tag_updated_successfully' => 'Oznaka kartice je bila uspešno posodobljena.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Agents
        |--------------------------------------------------------------------------
        */
        'agents'               => [
            'open_tickets'                       => 'Odprte kartice',
            'closed_tickets'                     => 'Zaprte kartice',
            'last_signed_in'                     => 'Zadnja prijava',
            'statistics'                         => 'Statistika',
            'assign_agent'                       => 'Dodeli agenta',
            'edit_agent'                         => 'Uredi agenta',
            'designation'                        => 'Naziv',
            'bio'                                => 'Kratka predstavitev',
            'description'                        => 'Najprej ustvarite vlogo preko <a href=":role_url" target="_blank">Ustvari vlogo</a>, nato ustvarite administratorja preko <a href=":administrator_url" target="_blank">Ustvari administratorja</a>. Dodelite ustvarjeno vlogo administratorju in ga označite kot podpornega agenta.',
            'assigned_successfully'              => 'Agent je bil uspešno dodeljen.',
            'already_assigned_or_no_change'      => 'Agent je že dodeljen ali ni prišlo do sprememb.',
            'never_logged_in'                    => 'Nikoli prijavljen',
            'confirm_activate_text'              => 'Ali res želite aktivirati te agente?',
            'confirm_deactivate_text'            => 'Ali res želite onemogočiti te agente?',
            'confirm_delete_text'                => 'Ali res želite odstraniti te agente iz podpore?',
            'yes_activate_it'                    => 'Da, aktiviraj!',
            'yes_deactivate_it'                  => 'Da, onemogoči!',
            'yes_release_it'                     => 'Da, odstrani!',
            'deactivated_successfully'           => 'Agent je bil uspešno onemogočen.',
            'updated_successfully'               => 'Agent je bil uspešno posodobljen.',
            'deleted_successfully'               => 'Agent je bil uspešno odstranjen iz podpore.',
            'no_valid_selected_for_deactivation' => 'Noben veljaven agent ni bil izbran za onemogočitev.',
            'activate_selected'                  => 'Aktiviraj izbrane',
            'activated_multiple_successfully'    => ':count agentov je bilo uspešno aktiviranih.',
            'deactivate_selected'                => 'Onemogoči izbrane',
            'deactivated_multiple_successfully'  => ':count agentov je bilo uspešno onemogočenih.',
            'delete_selected'                    => 'Odstrani izbrane',
            'deleted_multiple_successfully'      => ':count agentov je bilo uspešno odstranjenih iz podpore.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Categories
        |--------------------------------------------------------------------------
        */
        'categories'           => [
            'title_singular'                => 'Kategorija podpore',
            'title_plural'                  => 'Kategorije podpore',
            'name'                          => 'Ime kategorije',
            'icon'                          => 'Ikona',
            'name_placeholder'              => 'npr., Tehnične težave, Računovodske poizvedbe',
            'slug_placeholder'              => 'npr., tehnicne-tezave',
            'description_placeholder'       => 'Kratko opišite kategorijo',
            'add_new'                       => 'Dodaj novo kategorijo',
            'edit'                          => 'Uredi kategorijo',
            'details'                       => 'Podrobnosti kategorije',
            'select_icon'                   => 'Izberi ikono',
            'search_icons'                  => 'Išči ikone...',
            'created_successfully'          => 'Kategorija podpore je bila uspešno ustvarjena!',
            'updated_successfully'          => 'Kategorija podpore je bila uspešno posodobljena!',
            'deleted_successfully'          => 'Kategorija podpore je bila uspešno izbrisana!',
            'confirm_activate'              => 'Ali ste prepričani, da želite aktivirati izbrane kategorije?',
            'confirm_deactivate'            => 'Ali ste prepričani, da želite onemogočiti izbrane kategorije?',
            'confirm_delete'                => 'Ali ste prepričani, da želite trajno izbrisati izbrane kategorije?',
            'activated_successfully'        => ':count kategorij je bilo uspešno aktiviranih.',
            'deactivated_successfully'      => ':count kategorij je bilo uspešno onemogočenih.',
            'deleted_multiple_successfully' => ':count kategorij je bilo uspešno izbrisanih.',
            'slug_auto_generated_help'      => 'Slug bo samodejno ustvarjen iz imena, če je polje prazno.',
            'browse_by_category'            => 'Brskaj po kategorijah',
            'published_on'                  => 'Objavljeno dne',
            'related_questions'             => 'Sorodna vprašanja',
        ],
        /*
        |--------------------------------------------------------------------------
        | Support Tickets
        |--------------------------------------------------------------------------
        */
        'tickets'              => [
            'title_singular'                        => 'Podporna kartica',
            'title_plural'                          => 'Podporne kartice',
            'create_ticket'                         => 'Ustvari kartico',
            'view_ticket'                           => 'Ogled kartice',
            'created_successfully'                  => 'Podporna kartica je bila uspešno ustvarjena!',
            'updated_successfully'                  => 'Podporna kartica je bila uspešno posodobljena!',
            'deleted_successfully'                  => 'Podporna kartica je bila uspešno izbrisana!',
            'status_updated_successfully'           => 'Status kartice je bil uspešno posodobljen!',
            'assigned_successfully'                 => 'Kartica je bila uspešno dodeljena!',
            'tags_updated_successfully'             => 'Oznake kartice so bile uspešno posodobljene!',
            'confirm_action'                        => 'Pripravljeni ste za :action :length kartic.',
            'opened_successfully'                   => ':count kartic je bilo uspešno odprtih!',
            'closed_successfully'                   => ':count kartic je bilo uspešno zaprtih!',
            'marked_pending_successfully'           => ':count kartic je bilo uspešno označenih kot čakajoče!',
            'assigned_multiple_successfully'        => ':count kartic je bilo uspešno dodeljenih agentu :agent!',
            'deleted_multiple_successfully'         => ':count kartic je bilo uspešno izbrisanih!',
            'starred_successfully'                  => 'Status zvezdice kartice je bil uspešno posodobljen!',
            'category'                              => 'Kategorija',
            'assigned_agent'                        => 'Dodeljeni agent',
            'last_replied'                          => 'Zadnji odgovor',
            'priority'                              => 'Prednost',
            'customer_name'                         => 'Ime stranke',
            'customer_email'                        => 'E-pošta stranke',
            'please_select_an_agent_for_assignment' => 'Prosimo, izberite agenta za dodelitev.',
            'search_placeholder'                    => 'Išči kartice...',
            'filter_by_status'                      => 'Filtriraj po statusu',
            'filter_by_priority'                    => 'Filtriraj po prednosti',
            'assigned_to'                           => 'Dodeljeno :agent_name',
            'unassigned'                            => 'Nedodeljeno',
            'last_updated'                          => 'Posodobljeno pred :time',
            'no_tickets_found'                      => 'Ni najdenih kartic, ki bi ustrezale vašim kriterijem.',
            'category_help'                         => 'S katero kategorijo potrebujete pomoč?',
            'subject_help'                          => 'Na splošno, o čem je ta kartica?',
            'subject_placeholder'                   => 'O čem je ta kartica?',
            'related_url'                           => 'Povezana povezava',
            'related_url_help'                      => 'Neobvezno, a zelo uporabno.',
            'description_help'                      => 'Prosimo, podajte čim bolj podroben opis te kartice.',
            'description_placeholder'               => 'Kako vam lahko danes pomagamo?',
            'agent_help'                            => 'Po želji dodelite agenta tej kartici.',
            'select_agent'                          => 'Izberite agenta',
            'file_type_not_allowed'                 => 'Tip datoteke ni dovoljen.',
            'file_extension_not_allowed'            => 'Končnica datoteke ni dovoljena.',
            'file_too_large'                        => 'Datoteka je prevelika. Največja dovoljena velikost je :max_size.',
            'one_or_more_files_invalid'             => 'Ena ali več izbranih datotek je neveljavnih (tip ali velikost). Preverite in poskusite znova.',
            'max_attachments_exceeded'              => 'Največje število priponk je :max.',
            'make_public'                           => 'Naredi to kartico javno',
            'is_public_customer_notes'              => 'Privzeto lahko vaše kartice vidi in nanje odgovarja le podporna ekipa.',
            'is_public_help'                        => '<i>Javna kartica,</i> pa omogoča celotni skupnosti ogled in odgovor. <b>Opozorilo: ne morejo videti informacij iz “zasebnih” polj zgoraj.</b>',
            'status_help'                           => 'Trenutni status kartice.',
            'select_status'                         => 'Izberite status',
            'priority_help'                         => 'Stopnja pomembnosti kartice.',
            'select_priority'                       => 'Izberite prednost',
            'customer_help'                         => 'Izberite stranko, za katero se ustvarja kartica.',
            'select_customer'                       => 'Izberite stranko',
            'private_ticket'                        => 'ZASEBNA KARTICA',
            'original_description'                  => 'Izvirni opis kartice',
            'no_replies_yet'                        => 'Odgovori ali opombe še niso bili dodani.',
            'post_a_reply'                          => 'Dodaj odgovor',
            'post_a_note'                           => 'Dodaj opombo',
            'reply_placeholder'                     => 'Dodaj v pogovor...',
            'private_note'                          => 'Zasebna opomba',
            'internal_note'                         => 'Notranja opomba',
            'public_reply'                          => 'Javni odgovor',
            'post_reply'                            => 'Objavi odgovor',
            'post_note'                             => 'Objavi opombo',
            'ticket_details'                        => 'PODATKI KARTICE',
            'contact'                               => 'Kontakt',
            'assigned'                              => 'DODELJENO',
            'created'                               => 'USTVARJENO',
            'response'                              => 'ODGOVOR',
            'public_ticket'                         => 'JAVNA KARTICA',
            'private'                               => 'Zasebno',
            'public'                                => 'Javno',
            'privately_reply'                       => 'Odgovori zasebno',
            'add_customer_note_btn'                 => 'Dodaj opombo stranke',
            'no_response_yet'                       => 'Še ni odgovora',
            'delete_ticket'                         => 'Izbriši to kartico',
            'ticket_tags'                           => 'OZNAKE KARTICE',
            'select_some_options'                   => 'Izberite nekaj možnosti',
            'reply_added_successfully'              => 'Odgovor je bil uspešno dodan!',
            'attachments'                           => 'Priponke',
            'needs_response'                        => 'Potreben odgovor',
            'customer_notes'                        => 'Opombe stranke',
            'close_ticket'                          => 'Zapri kartico',
            'ticket_closed_successfully'            => 'Kartica je bila uspešno zaprta!',
            'select_tags'                           => 'Izberite oznake',
            'update_priority_successfully'          => 'Prednost kartice je bila uspešno posodobljena!',
            'category_updated_successfully'         => 'Kategorija kartice je bila uspešno posodobljena!',
            'replied_privately'                     => 'Odgovorjeno zasebno',
            'privacy_policy_note'                   => 'Preberite našo <a href=":privacy_policy_url" target="_blank">Politiko zasebnosti</a> in si oglejte, kako ravnamo z vašimi osebnimi podatki',
            'all_cleared'                           => 'Vse očiščeno.',

            'statuses'   => [
                'pending'    => 'Čakajoče',
                'open'       => 'Odprto',
                'resolved'   => 'Rešeno',
                'closed'     => 'Zaprto',
                'replied'    => 'Odgovorjeno (-stranka)',
                'starred'    => 'Z zvezdico',
                'unassigned' => 'Nedodeljeno',
            ],
            'priorities' => [
                'low'    => 'Nizka',
                'medium' => 'Srednja',
                'high'   => 'Visoka',
                'urgent' => 'Nujno',
            ],
            'filters'    => [
                'title'               => 'Filtri',
                'all_tickets'         => 'Vse kartice',
                'my_tickets'          => 'Moje kartice',
                'unassigned_tickets'  => 'Nedodeljene kartice',
                'starred_tickets'     => 'Kartice z zvezdico',
                'categories'          => 'Kategorije',
                'no_categories_found' => 'Ni najdenih kategorij',
                'agents'              => 'Agenti',
                'no_agents_found'     => 'Ni najdenih agentov',
            ],
            'stats'      => [
                'title'              => 'Hitra statistika',
                'total_tickets'      => 'Skupno kartic',
                'open_tickets'       => 'Odprte kartice',
                'closed_last_7_days' => 'Zaprte (zadnjih 7 dni)',
                'closed_older_than'  => 'Starejše od 7 dni',
                'closed_other'       => 'V zadnjih 7 dneh (drugo)',
                'closed_2days'       => 'Posodobljeno pred 2 dnevoma',
                'updated_today'      => 'Posodobljeno danes',
                'no_action_needed'   => 'Ni potrebnih ukrepov',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Replies
        |--------------------------------------------------------------------------
        */
        'replies'              => [
            'added_successfully'        => 'Odgovor je bil uspešno dodan!',
            'message'                   => 'Sporočilo odgovora',
            'add_reply'                 => 'Dodaj odgovor',
            'your_reply'                => 'Vaš odgovor',
            'title_plural'              => 'Odgovori',
            'edited'                    => 'Urejeno',
            'note_updated_successfully' => 'Opomba je bila uspešno posodobljena.',
            'fetched_successfully'      => 'Odgovor uspešno pridobljen.',
            'not_found'                 => 'Odgovor ne pripada izbrani kartici.',
            'updated_successfully'      => 'Odgovor uspešno posodobljen.',
            'deleted_successfully'      => 'Odgovor uspešno izbrisan.',
            'delete_failed'             => 'Brisanje odgovora ni uspelo.',
        ],

        /*
        |--------------------------------------------------------------------------
        | Ticket Attachments
        |--------------------------------------------------------------------------
        */
        'attachments'          => [
            'title_plural'         => 'Priponke',
            'add_attachments'      => 'Dodaj priponke',
            'not_found'            => 'Priponka ne pripada izbrani kartici.',
            'deleted_successfully' => 'Priponka je bila uspešno izbrisana!',
            'delete_failed'        => 'Brisanje priponke ni uspelo.',
        ],
        /*
        |--------------------------------------------------------------------------
        | Ticket Tags
        |--------------------------------------------------------------------------
        */
        'tags'                 => [
            'title_singular'                => 'Oznaka kartice',
            'title_plural'                  => 'Oznake kartic',
            'name'                          => 'Ime oznake',
            'name_placeholder'              => 'Vnesite ime oznake',
            'description'                   => 'Opis oznake',
            'color'                         => 'Barva',
            'add_new'                       => 'Dodaj novo oznako',
            'edit'                          => 'Uredi oznako',
            'created_successfully'          => 'Oznaka je bila uspešno ustvarjena!',
            'updated_successfully'          => 'Oznaka je bila uspešno posodobljena!',
            'deleted_successfully'          => 'Oznaka je bila uspešno izbrisana!',
            'deleted_multiple_successfully' => ':count oznak je bilo uspešno izbrisanih!',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Articles
        |--------------------------------------------------------------------------
        */
        'articles'             => [
            'title_singular'             => 'Podporni članek',
            'title_plural'               => 'Podporni članki',
            'create'                     => 'Ustvari podporni članek',
            'edit'                       => 'Uredi podporni članek',
            'published_status'           => 'Status objave',
            'featured_status'            => 'Priporočen status',
            'draft'                      => 'Osnutek',
            'published'                  => 'Objavljeno',
            'featured'                   => 'Priporočeno',
            'views'                      => 'Ogledi',
            'confirm_delete_text'        => 'Tehničnega članka ne boste mogli obnoviti!',
            'deleted_successfully'       => 'Podporni članek je bil uspešno izbrisan.',
            'delete_failed'              => 'Brisanje podpornega članka ni uspelo.',
            'created_successfully'       => 'Podporni članek je bil uspešno ustvarjen!',
            'updated_successfully'       => 'Podporni članek je bil uspešno posodobljen!',
            'update_failed'              => 'Posodobitev članka ni uspela.',
            'published_successfully'     => 'Članek je bil uspešno objavljen.',
            'unpublished_successfully'   => 'Članek je bil uspešno preklican iz objave.',
            'featured_successfully'      => 'Članek je bil uspešno označen kot priporočen.',
            'unfeatured_successfully'    => 'Članek je bil uspešno odstranjen s priporočenih.',
            'slug_help'                  => 'Pustite prazno za samodejno generiranje iz naslova. Mora biti edinstveno.',
            'is_published_help'          => 'Pustite neoznačeno, da shranite kot osnutek',
            'confirm_publish'            => 'Ali ste prepričani, da želite objaviti izbrane članke?',
            'confirm_unpublish'          => 'Ali ste prepričani, da želite preklicati objavo izbranih člankov?',
            'confirm_feature'            => 'Ali ste prepričani, da želite označiti izbrane članke kot priporočen?',
            'confirm_unfeature'          => 'Ali ste prepričani, da želite odstraniti izbrane članke s priporočenih?',
            'confirm_delete'             => 'Ali ste prepričani, da želite trajno izbrisati izbrane članke?',
            'batch_action_success'       => ':count člankov je bilo uspešno :action.',
            'batch_action_failed'        => 'Skupinska akcija ni uspela.',
            'batch_action_failed_no_ids' => 'Skupinska akcija ni uspela. Ni poslanih ID-jev.',
            'help_text'                  => 'Dobrodošli v podpori! Imamo :count člankov za vašo pomoč',
            'search_placeholder'         => 'Išči naše članke ali pogosta vprašanja...',
            'read_article'               => 'Preberi članek',
            'featured_articles'          => 'Priporočeni članki',
            'most_viewed_articles'       => 'Najbolj gledani članki',
            'question'                   => 'Vprašanje',
            'last_updated_on'            => 'Zadnja posodobitev',
        ],

        /*
        |--------------------------------------------------------------------------
        | Batch Actions - General messages for all batch actions
        |--------------------------------------------------------------------------
        */
        'batch_actions'        => [
            'select_action'                         => 'Izberite skupinsko akcijo',
            'select_action_warning'                 => 'Prosimo, izberite skupinsko akcijo.',
            'select_items_warning'                  => 'Prosimo, izberite vsaj en element.',
            'confirm_title'                         => 'Ali ste prepričani?',
            'confirm_text'                          => 'Pripravljeni ste izvesti skupinsko akcijo: :action na izbranih elementih.',
            'you_will_be_able_to_revert_this_later' => 'To kasneje ne bo mogoče razveljaviti.',
            'bulk_actions'                          => 'Skupinske akcije',
        ],

        /*
        |--------------------------------------------------------------------------
        | Support Analytics
        |--------------------------------------------------------------------------
        */
        'analytics'            => [
            'new_tickets'              => 'Nove kartice',
            'agent_responses'          => 'Odgovori agentov',
            'resolved_tickets'         => 'Rešene kartice',
            'analytics_overview'       => 'Pregled analitike',
            'all_categories'           => 'Vse kategorije',
            'all_agents'               => 'Vsi agenti',
            'apply_filters'            => 'Uporabi filtre',
            'no_change_from_yesterday' => 'Brez spremembe od včeraj',
            'from_yesterday'           => 'Od včeraj',
            'agent_replies'            => 'Odgovori agentov',
            'updated'                  => 'Podatki analitike so bili uspešno posodobljeni!',
            'date_range'               => 'Časovno obdobje',
            'select_date_range'        => 'Izberite časovno obdobje',
            'agent'                    => 'Agent',
        ],

        /*
        |--------------------------------------------------------------------------
        | Policy Denial Messages
        |--------------------------------------------------------------------------
        */
        'policies'             => [
            'support_categories' => [
                'view_any'     => 'Nimate dovoljenja za ogled podpornih kategorij.',
                'view'         => 'Nimate dovoljenja za ogled te podporne kategorije.',
                'create'       => 'Nimate dovoljenja za ustvarjanje podpornih kategorij.',
                'update'       => 'Nimate dovoljenja za posodobitev te podporne kategorije.',
                'delete'       => 'Nimate dovoljenja za izbris te podporne kategorije.',
                'delete_any'   => 'Nimate dovoljenja za izbris več podpornih kategorij.',
                'batch_action' => 'Nimate dovoljenja za izvajanje skupinskih akcij na podpornih kategorijah.',
            ],
            'support_articles'   => [
                'view_any'     => 'Nimate dovoljenja za ogled podpornih člankov.',
                'view'         => 'Nimate dovoljenja za ogled tega podpornega članka.',
                'create'       => 'Nimate dovoljenja za ustvarjanje podpornih člankov.',
                'update'       => 'Nimate dovoljenja za posodobitev tega podpornega članka.',
                'delete'       => 'Nimate dovoljenja za izbris tega podpornega članka.',
                'delete_any'   => 'Nimate dovoljenja za izbris več podpornih člankov.',
                'batch_action' => 'Nimate dovoljenja za izvajanje skupinskih akcij na podpornih člankih.',
                'publish'      => 'Nimate dovoljenja za objavo ali preklic objave tega članka.',
                'feature'      => 'Nimate dovoljenja za označitev ali odstranitev tega članka s priporočenih.',
            ],
            'support_tickets'    => [
                'view_any'      => 'Nimate dovoljenja za ogled podpornih kartic.',
                'view'          => 'Nimate dovoljenja za ogled te podporne kartice.',
                'create'        => 'Nimate dovoljenja za ustvarjanje podpornih kartic.',
                'update'        => 'Nimate dovoljenja za posodobitev te kartice.',
                'delete'        => 'Nimate dovoljenja za izbris te kartice.',
                'reply'         => 'Nimate dovoljenja za odgovor na to kartico ali je zaprta.',
                'update_status' => 'Nimate dovoljenja za posodobitev statusa te kartice.',
                'assign'        => 'Nimate dovoljenja za dodelitev te kartice.',
                'manage_tags'   => 'Nimate dovoljenja za upravljanje oznak te kartice.',
                'delete_any'    => 'Nimate dovoljenja za izbris več podpornih kartic.',
                'batch_action'  => 'Nimate dovoljenja za izvajanje skupinskih akcij na podpornih karticah.',
            ],
            'ticket_replies'     => [
                'update'     => 'Nimate dovoljenja za posodobitev tega odgovora ali je okno za urejanje poteklo.',
                'delete'     => 'Nimate dovoljenja za izbris tega odgovora.',
                'delete_any' => 'Nimate dovoljenja za izbris več odgovorov na kartice.',
            ],
            'ticket_attachments' => [
                'view'       => 'Nimate dovoljenja za ogled te priponke.',
                'delete'     => 'Nimate dovoljenja za izbris te priponke.',
                'delete_any' => 'Nimate dovoljenja za izbris več priponk kartic.',
            ],
        ],

    ];
