# FurniCraft Capstone Reviewer

Gabay sa pag-unawa at pagdepensa sa kasalukuyang FurniCraft repository.

## Ano ang system

Ang FurniCraft ay Laravel web application para sa furniture shop: public catalog, customer accounts at preferences, personalized recommendations, AI chat, room-photo fitting/preview, reservations, at admin operations. PHP/Laravel ang backend; Blade templates, JavaScript at CSS ang browser UI; database migrations ang nagtatakda ng schema.

## Paano dumadaloy ang request

1. Routes ang nagmamapa ng URL papunta sa controller.
2. Form Requests at middleware ang nagva-validate ng input at nagbabantay ng access.
3. Controller ang nag-uugnay sa application flow; service ang may reusable business rules.
4. Models ang nagbabasa/nagsusulat ng database records.
5. Blade view/JSON ang response; JavaScript at CSS ang browser experience.
6. Queue jobs ang gumagawa ng mabibigat na trabaho sa background; notifications ang naghahatid ng status sa user.

## Pangunahing user flow

- Catalog: customer searches/filters products, opens details, and saves favorites.
- Recommendations/chat: preferences and budget help rank products; AI chat supports customer questions.
- Room fitting: uploaded photo passes through privacy, storage and image processing before analysis/preview. Result is a visual estimate, not proof of physical fit.
- Reservation: selected item/customization is sent to the shop for review; status and notifications track it.
- Admin: staff manages products, customers, reservations, reports, privacy audit and placement evaluation.

## Folder map

app = Laravel application logic; bootstrap = startup; config = system settings; database = schema and starter data; docs = technical guides and artifacts; public = served assets; resources = views and frontend sources; routes = URL mappings; storage = runtime locations; tests = automated checks; tools = developer utilities; output/tmp = generated working artifacts.

vendor and node_modules contain third-party packages installed from Composer/npm lockfiles and are not FurniCraft-authored code. .git, .vscode, .21st and test-results are tool or generated state, not part of this source-file inventory.

## File-by-file catalog

Ang pangunahing catalog sa ibaba ay naglilista ng 544 non-dependency source at project-artifact files. Idinagdag sa hiwalay na seksyon ang mga hidden local configuration/tool files. Hindi inililista paisa-isa ang vendor/ at node_modules/ dahil third-party dependency files ang mga iyon; hindi rin inililista ang aktuwal na laman ng storage/ dahil runtime uploads, logs, sessions at cache iyon at maaaring may user data. Ipinaliwanag ang mga folder na iyon ayon sa gamit. Walang .env secret value na inilalantad.

### Hidden local/configuration files

- **.editorconfig** — Pare-parehong formatting at line-ending settings para sa editors.
- **.env** — Local environment values at credentials; ginagamit ng Laravel sa DB, mail, AI at app settings. Huwag i-commit o ipakita ang secret values.
- **.env.example** — Template ng required local environment variables.
- **.env.production.example** — Template ng production environment values; punan nang ligtas sa deployment.
- **.gitattributes** — Git rules para sa line endings at file export behavior.
- **.gitignore** — Files/folders na hindi dapat isama sa Git, gaya ng generated dependencies at local runtime data.
- **.phpunit.result.cache** — Local cache ng resulta ng nakaraang PHPUnit run; test tooling lang ito.
- **.21st/design.json** — Local design-tool/project configuration; hindi runtime logic ng FurniCraft.
- **.vscode/settings.json** — Workspace-specific editor settings.
- **.vscode/tailwind-css-data.json** — Custom Tailwind class suggestions para sa VS Code editor.
- **test-results/.last-run.json** — Playwright state tungkol sa huling browser-test run.

### Runtime at dependency folders

- **storage/app/private/** — Private uploaded files; access dapat sa pamamagitan ng application authorization.
- **storage/app/public/** — Publicly served uploads/assets kung ginagamit ng app.
- **storage/framework/cache/** — Laravel temporary cached values.
- **storage/framework/sessions/** — Session state ng users.
- **storage/framework/testing/** — Temporary testing files.
- **storage/framework/views/** — Compiled Blade templates.
- **storage/logs/** — Application logs para sa debugging/operations.
- **storage/certs/** — Certificate/config support para sa secure mail transport.
- Ang .gitignore placeholder files sa mga storage subfolder ay nagpapanatili ng empty folder sa Git; hindi ito feature logic. Ang aktuwal na runtime files ay hindi inililista dahil nagbabago kada run at maaaring may private customer data.
- **vendor/** — Third-party PHP packages na naka-install ayon sa composer.lock.
- **node_modules/** — Third-party JavaScript packages na naka-install ayon sa package-lock.json.
- **.git/** — Git history at metadata; hindi bahagi ng application.
- **output/** at **tmp/** — Generated documents, images at temporary processing artifacts na ginagamit sa development/review, hindi direktang source ng live behavior.
### app/

- **app/Console/Commands/AuditRoomPhotoStorage.php** — Sinusuri kung ligtas at wasto ang pagkakaayos ng nakaimbak na room photos.
- **app/Console/Commands/BenchmarkRoomPreview.php** — Sinusukat ang oras/resource behavior ng room-preview pipeline.
- **app/Console/Commands/CheckOperations.php** — Nagpapatakbo ng operational checks sa queue, scheduler at app dependencies.
- **app/Console/Commands/CheckProductionReadiness.php** — Sinusuri ang production configuration at ipinapakita ang mga nawawalang requirement.
- **app/Console/Commands/CreateAdminAccount.php** — Lumilikha ng admin account sa pamamagitan ng command line.
- **app/Console/Commands/ExpireReservations.php** — Minamarkahang expired ang reservations na lampas na sa itinakdang oras.
- **app/Console/Commands/PruneFeedbackReports.php** — Tinatanggal o nililinis ang feedback records lampas sa retention period.
- **app/Console/Commands/PruneRegistrationVerificationCodes.php** — Nililinis ang expired registration verification codes.
- **app/Console/Commands/PruneRoomImages.php** — Nililinis ang room photos ayon sa retention at consent rules.
- **app/Console/Commands/RecoverReservationNotifications.php** — Muling pinoproseso ang reservation notifications na hindi naipadala.
- **app/Console/Commands/RemindReservationVisits.php** — Nagpapadala ng paalala bago ang naka-iskedyul na shop visit.
- **app/Console/Commands/RetryFileDeletions.php** — Muling sinusubukan ang pending file deletions na pumalya.
- **app/Console/Commands/SecureRoomPhotos.php** — Inaayos ang permissions/storage safety ng room photo files.
- **app/Http/Controllers/Admin/CustomerController.php** — Namamahala ng customer search, profile view at account activation sa admin panel.
- **app/Http/Controllers/Admin/DashboardController.php** — Ipinapakita ang admin overview, kabuuang bilang at operational status.
- **app/Http/Controllers/Admin/PrivacyAuditController.php** — Ipinapakita ang privacy audit events para masuri ng admin.
- **app/Http/Controllers/Admin/ProductController.php** — Nagdadagdag, nag-eedit at namamahala sa catalog products.
- **app/Http/Controllers/Admin/ReportsController.php** — Gumagawa at nagda-download ng filtered business reports.
- **app/Http/Controllers/Admin/ReservationController.php** — Pinapamahalaan ng staff ang reservation status at follow-up.
- **app/Http/Controllers/Admin/ReservationPreviewController.php** — Ipinapakita sa staff ang room preview na kasama sa reservation.
- **app/Http/Controllers/Admin/RoomPlacementEvaluationController.php** — Nagtatala at nagpapakita ng admin evaluation sa room furniture placement.
- **app/Http/Controllers/AuthController.php** — Handles registration, login, email verification and authenticated access.
- **app/Http/Controllers/CatalogController.php** — Provides catalog listings, product details, search, filters and favorites.
- **app/Http/Controllers/ChatController.php** — Accepts customer chat messages and calls the AI chat service.
- **app/Http/Controllers/Controller.php** — Base controller shared by Laravel HTTP controllers.
- **app/Http/Controllers/DashboardController.php** — Ipinapakita ang admin overview, kabuuang bilang at operational status.
- **app/Http/Controllers/FeedbackController.php** — Accepts customer feedback reports.
- **app/Http/Controllers/HealthController.php** — Exposes an application health/readiness response.
- **app/Http/Controllers/PasswordResetController.php** — Runs the password recovery code workflow.
- **app/Http/Controllers/PreferenceController.php** — Reads and saves customer preferences.
- **app/Http/Controllers/ProfileController.php** — Updates customer profile and deactivation requests.
- **app/Http/Controllers/RecommendationController.php** — Creates and displays personalized furniture recommendations.
- **app/Http/Controllers/RecommendationFeedbackController.php** — Records customer evaluation of recommendations.
- **app/Http/Controllers/ReservationController.php** — Pinapamahalaan ng staff ang reservation status at follow-up.
- **app/Http/Controllers/RoomController.php** — Accepts room photos and starts room analysis.
- **app/Http/Controllers/RoomPhotoController.php** — Serves stored room photos through protected access.
- **app/Http/Controllers/RoomPreviewV2Controller.php** — Runs the version 2 room preview workflow.
- **app/Http/Controllers/RoomVisualizationController.php** — Serves the room visualizer page and its actions.
- **app/Http/Middleware/EnsureAccountIsActive.php** — Request middleware enforcing the access/security behavior represented by EnsureAccountIsActive.php.
- **app/Http/Middleware/EnsureRole.php** — Request middleware enforcing the access/security behavior represented by EnsureRole.php.
- **app/Http/Middleware/ForceHttps.php** — Request middleware enforcing the access/security behavior represented by ForceHttps.php.
- **app/Http/Middleware/SecurityHeaders.php** — Request middleware enforcing the access/security behavior represented by SecurityHeaders.php.
- **app/Http/Requests/Admin/CustomerActivationRequest.php** — Form Request that validates and normalizes input for CustomerActivationRequest.php before controller processing.
- **app/Http/Requests/Admin/CustomerSearchRequest.php** — Form Request that validates and normalizes input for CustomerSearchRequest.php before controller processing.
- **app/Http/Requests/Admin/ProductRequest.php** — Form Request that validates and normalizes input for ProductRequest.php before controller processing.
- **app/Http/Requests/Admin/ProductStatusRequest.php** — Form Request that validates and normalizes input for ProductStatusRequest.php before controller processing.
- **app/Http/Requests/Admin/ReportFiltersRequest.php** — Form Request that validates and normalizes input for ReportFiltersRequest.php before controller processing.
- **app/Http/Requests/Admin/ReservationUpdateRequest.php** — Form Request that validates and normalizes input for ReservationUpdateRequest.php before controller processing.
- **app/Http/Requests/Admin/RoomPlacementEvaluationRequest.php** — Form Request that validates and normalizes input for RoomPlacementEvaluationRequest.php before controller processing.
- **app/Http/Requests/ChatMessageRequest.php** — Form Request that validates and normalizes input for ChatMessageRequest.php before controller processing.
- **app/Http/Requests/FeedbackReportRequest.php** — Form Request that validates and normalizes input for FeedbackReportRequest.php before controller processing.
- **app/Http/Requests/LoginRequest.php** — Form Request that validates and normalizes input for LoginRequest.php before controller processing.
- **app/Http/Requests/PasswordResetCodeRequest.php** — Form Request that validates and normalizes input for PasswordResetCodeRequest.php before controller processing.
- **app/Http/Requests/PasswordResetEmailRequest.php** — Form Request that validates and normalizes input for PasswordResetEmailRequest.php before controller processing.
- **app/Http/Requests/PasswordResetRequest.php** — Form Request that validates and normalizes input for PasswordResetRequest.php before controller processing.
- **app/Http/Requests/PreferenceUpdateRequest.php** — Form Request that validates and normalizes input for PreferenceUpdateRequest.php before controller processing.
- **app/Http/Requests/ProfileDeletionRequest.php** — Form Request that validates and normalizes input for ProfileDeletionRequest.php before controller processing.
- **app/Http/Requests/ProfilePasswordUpdateRequest.php** — Form Request that validates and normalizes input for ProfilePasswordUpdateRequest.php before controller processing.
- **app/Http/Requests/ProfileUpdateRequest.php** — Form Request that validates and normalizes input for ProfileUpdateRequest.php before controller processing.
- **app/Http/Requests/RecommendationFeedbackRequest.php** — Form Request that validates and normalizes input for RecommendationFeedbackRequest.php before controller processing.
- **app/Http/Requests/RecommendationGenerateRequest.php** — Form Request that validates and normalizes input for RecommendationGenerateRequest.php before controller processing.
- **app/Http/Requests/RegisterRequest.php** — Form Request that validates and normalizes input for RegisterRequest.php before controller processing.
- **app/Http/Requests/ResendVerificationRequest.php** — Form Request that validates and normalizes input for ResendVerificationRequest.php before controller processing.
- **app/Http/Requests/ReservationCancelRequest.php** — Form Request that validates and normalizes input for ReservationCancelRequest.php before controller processing.
- **app/Http/Requests/ReservationStoreRequest.php** — Form Request that validates and normalizes input for ReservationStoreRequest.php before controller processing.
- **app/Http/Requests/RoomPreviewV2StoreRequest.php** — Form Request that validates and normalizes input for RoomPreviewV2StoreRequest.php before controller processing.
- **app/Http/Requests/RoomStoreRequest.php** — Form Request that validates and normalizes input for RoomStoreRequest.php before controller processing.
- **app/Http/Requests/VerifyEmailRequest.php** — Form Request that validates and normalizes input for VerifyEmailRequest.php before controller processing.
- **app/Http/Requests/VerifyRoomPreviewRequest.php** — Form Request that validates and normalizes input for VerifyRoomPreviewRequest.php before controller processing.
- **app/Jobs/AnalyzeRoomImage.php** — Background queue job for AnalyzeRoomImage.php.
- **app/Jobs/GenerateRoomPreview.php** — Background queue job for GenerateRoomPreview.php.
- **app/Jobs/GenerateRoomPreviewV2.php** — Background queue job for GenerateRoomPreviewV2.php.
- **app/Listeners/MarkNotificationFailed.php** — Project configuration or support file used by the application or developer tooling.
- **app/Listeners/MarkNotificationSent.php** — Project configuration or support file used by the application or developer tooling.
- **app/Mail/FeedbackReceived.php** — Project configuration or support file used by the application or developer tooling.
- **app/Models/AiServiceEvent.php** — Eloquent model for the AiServiceEvent.php database entity, its fields and relationships.
- **app/Models/AiUsageAlert.php** — Eloquent model for the AiUsageAlert.php database entity, its fields and relationships.
- **app/Models/ChatMessage.php** — Eloquent model for the ChatMessage.php database entity, its fields and relationships.
- **app/Models/CustomerPreference.php** — Eloquent model for the CustomerPreference.php database entity, its fields and relationships.
- **app/Models/Favorite.php** — Eloquent model for the Favorite.php database entity, its fields and relationships.
- **app/Models/FeedbackReport.php** — Eloquent model for the FeedbackReport.php database entity, its fields and relationships.
- **app/Models/FurnitureProduct.php** — Eloquent model for the FurnitureProduct.php database entity, its fields and relationships.
- **app/Models/FurnitureViewEvent.php** — Eloquent model for the FurnitureViewEvent.php database entity, its fields and relationships.
- **app/Models/NotificationLog.php** — Eloquent model for the NotificationLog.php database entity, its fields and relationships.
- **app/Models/PasswordResetCode.php** — Eloquent model for the PasswordResetCode.php database entity, its fields and relationships.
- **app/Models/PendingFileDeletion.php** — Eloquent model for the PendingFileDeletion.php database entity, its fields and relationships.
- **app/Models/PrivacyAuditEvent.php** — Eloquent model for the PrivacyAuditEvent.php database entity, its fields and relationships.
- **app/Models/Recommendation.php** — Eloquent model for the Recommendation.php database entity, its fields and relationships.
- **app/Models/RecommendationItem.php** — Eloquent model for the RecommendationItem.php database entity, its fields and relationships.
- **app/Models/RegistrationVerificationCode.php** — Eloquent model for the RegistrationVerificationCode.php database entity, its fields and relationships.
- **app/Models/Reservation.php** — Eloquent model for the Reservation.php database entity, its fields and relationships.
- **app/Models/ReservationTransaction.php** — Eloquent model for the ReservationTransaction.php database entity, its fields and relationships.
- **app/Models/RoomImage.php** — Eloquent model for the RoomImage.php database entity, its fields and relationships.
- **app/Models/RoomPlacementEvaluation.php** — Eloquent model for the RoomPlacementEvaluation.php database entity, its fields and relationships.
- **app/Models/RoomPreviewAnalysis.php** — Eloquent model for the RoomPreviewAnalysis.php database entity, its fields and relationships.
- **app/Models/RoomPreviewGeneration.php** — Eloquent model for the RoomPreviewGeneration.php database entity, its fields and relationships.
- **app/Models/User.php** — Eloquent model for the User.php database entity, its fields and relationships.
- **app/Notifications/AiUsageAlertNotification.php** — Notification behavior for the AiUsageAlertNotification.php event.
- **app/Notifications/Concerns/RecoversReservationDelivery.php** — Notification behavior for the RecoversReservationDelivery.php event.
- **app/Notifications/EmailVerificationCodeNotification.php** — Notification behavior for the EmailVerificationCodeNotification.php event.
- **app/Notifications/PasswordResetCodeNotification.php** — Notification behavior for the PasswordResetCodeNotification.php event.
- **app/Notifications/ReservationConfirmedNotification.php** — Notification behavior for the ReservationConfirmedNotification.php event.
- **app/Notifications/ReservationStatusNotification.php** — Notification behavior for the ReservationStatusNotification.php event.
- **app/Notifications/ReservationVisitReminderNotification.php** — Notification behavior for the ReservationVisitReminderNotification.php event.
- **app/Providers/AppServiceProvider.php** — Project configuration or support file used by the application or developer tooling.
- **app/Services/AiAggregateBudgetExceeded.php** — Reusable business/technical logic for AiAggregateBudgetExceeded.php.
- **app/Services/AiProvider.php** — Reusable business/technical logic for AiProvider.php.
- **app/Services/AiRequestBudgetExceeded.php** — Reusable business/technical logic for AiRequestBudgetExceeded.php.
- **app/Services/AiTelemetry.php** — Records AI provider usage events.
- **app/Services/AiUsageAlerts.php** — Reusable business/technical logic for AiUsageAlerts.php.
- **app/Services/AiUsageBudget.php** — Enforces AI request and aggregate spending limits.
- **app/Services/AiUsageOverview.php** — Reusable business/technical logic for AiUsageOverview.php.
- **app/Services/ChatPreferenceFilter.php** — Reusable business/technical logic for ChatPreferenceFilter.php.
- **app/Services/DoorwayGeometry.php** — Interprets doorway geometry for fit and clearance estimates.
- **app/Services/FileDeletionRecovery.php** — Retries pending file deletions.
- **app/Services/FurnitureDimensionDefaults.php** — Reusable business/technical logic for FurnitureDimensionDefaults.php.
- **app/Services/FurniturePlacementService.php** — Calculates and validates furniture positions in the room preview.
- **app/Services/GeminiChatService.php** — Sends customer chat prompts and formats AI responses.
- **app/Services/GeminiClient.php** — Sends requests to the Gemini provider and handles responses/errors.
- **app/Services/GeminiRoomVisionService.php** — Requests visual room and doorway analysis from Gemini.
- **app/Services/GeminiService.php** — Reusable business/technical logic for GeminiService.php.
- **app/Services/ImageMetadataStripper.php** — Removes potentially sensitive metadata from images.
- **app/Services/PasswordSessionSecurity.php** — Reusable business/technical logic for PasswordSessionSecurity.php.
- **app/Services/PrivacyAudit.php** — Records privacy-related audit events.
- **app/Services/ProductionReadiness.php** — Checks application configuration and dependencies for deployment readiness.
- **app/Services/RecommendationService.php** — Ranks product matches from customer preferences and budget.
- **app/Services/RegistrationVerificationService.php** — Creates, verifies and expires registration codes.
- **app/Services/ReservationPreview.php** — Reusable business/technical logic for ReservationPreview.php.
- **app/Services/ReservationStatusNotifier.php** — Sends notifications when reservation status changes.
- **app/Services/RoomImageOrientation.php** — Corrects uploaded image orientation using metadata.
- **app/Services/RoomPhotoService.php** — Manages safe storage, access and retention of room photos.
- **app/Services/RoomPreviewIdentity.php** — Reusable business/technical logic for RoomPreviewIdentity.php.
- **app/Services/RoomPreviewLightingService.php** — Applies lighting adjustments to the generated preview.
- **app/Services/RoomPreviewRasterService.php** — Composes room and furniture into a raster preview.
- **app/Services/RoomPreviewScaleService.php** — Maps room and furniture dimensions to preview scale.
- **app/Services/RoomPreviewV2Service.php** — Orchestrates room preview generation and output verification.
- **app/Support/NormalizesPhilippineMobileNumber.php** — Project configuration or support file used by the application or developer tooling.
- **app/Support/SafeRedirectTarget.php** — Project configuration or support file used by the application or developer tooling.

### artisan/

- **artisan** — Project configuration or support file used by the application or developer tooling.

### bootstrap/

- **bootstrap/app.php** — Project configuration or support file used by the application or developer tooling.
- **bootstrap/cache/.gitignore** — Project configuration or support file used by the application or developer tooling.
- **bootstrap/providers.php** — Project configuration or support file used by the application or developer tooling.

### composer.json/

- **composer.json** — Project configuration or support file used by the application or developer tooling.

### composer.lock/

- **composer.lock** — Project configuration or support file used by the application or developer tooling.

### config/

- **config/app.php** — Pangunahing Laravel settings gaya ng app name, timezone, locale at encryption key.
- **config/auth.php** — Authentication guards/providers at settings para sa users at login.
- **config/cache.php** — Itinatakda kung saan at paano iniimbak ang application cache.
- **config/database.php** — Database connection drivers at credentials na binabasa mula sa environment.
- **config/filesystems.php** — Storage disks at paths para sa private/public uploads.
- **config/furnicraft.php** — Mga custom FurniCraft feature settings at business defaults.
- **config/logging.php** — Log channels at kung saan isinusulat ang application errors.
- **config/mail.php** — Mail transport at sender settings para sa notifications.
- **config/privacy.php** — Privacy, consent at retention configuration para sa customer data.
- **config/queue.php** — Background queue connection at retry behavior.
- **config/roompreview.php** — Room-preview limits/defaults para sa image processing at placement.
- **config/services.php** — Credentials at integration settings para sa external services gaya ng Gemini.
- **config/session.php** — Session storage, lifetime, cookie at security settings.

### database/

- **database/factories/UserFactory.php** — Creates sample database records for tests and development.
- **database/migrations/0001_01_01_000000_create_users_table.php** — Database schema change described by 0001_01_01_000000_create_users_table.php.
- **database/migrations/0001_01_01_000001_create_cache_table.php** — Database schema change described by 0001_01_01_000001_create_cache_table.php.
- **database/migrations/0001_01_01_000002_create_jobs_table.php** — Database schema change described by 0001_01_01_000002_create_jobs_table.php.
- **database/migrations/2026_09_22_000001_create_furnicraft_tables.php** — Database schema change described by 2026_09_22_000001_create_furnicraft_tables.php.
- **database/migrations/2026_09_30_000001_add_furniture_type_to_recommendations_table.php** — Database schema change described by 2026_09_30_000001_add_furniture_type_to_recommendations_table.php.
- **database/migrations/2026_09_30_000002_add_furniture_type_to_customer_preferences_table.php** — Database schema change described by 2026_09_30_000002_add_furniture_type_to_customer_preferences_table.php.
- **database/migrations/2026_10_03_000001_add_analysis_state_to_room_images_table.php** — Database schema change described by 2026_10_03_000001_add_analysis_state_to_room_images_table.php.
- **database/migrations/2026_10_03_000002_add_recommendation_evaluation_fields.php** — Database schema change described by 2026_10_03_000002_add_recommendation_evaluation_fields.php.
- **database/migrations/2026_10_03_000003_create_usability_and_placement_evaluations.php** — Database schema change described by 2026_10_03_000003_create_usability_and_placement_evaluations.php.
- **database/migrations/2026_10_03_000004_add_room_photo_consent_and_privacy_audit.php** — Database schema change described by 2026_10_03_000004_add_room_photo_consent_and_privacy_audit.php.
- **database/migrations/2026_10_03_000005_create_ai_service_events_table.php** — Database schema change described by 2026_10_03_000005_create_ai_service_events_table.php.
- **database/migrations/2026_10_03_000006_add_ai_source_to_chat_messages.php** — Database schema change described by 2026_10_03_000006_add_ai_source_to_chat_messages.php.
- **database/migrations/2026_10_03_000007_create_room_preview_generations_table.php** — Database schema change described by 2026_10_03_000007_create_room_preview_generations_table.php.
- **database/migrations/2026_10_03_000008_extend_reservations_for_business_workflow.php** — Database schema change described by 2026_10_03_000008_extend_reservations_for_business_workflow.php.
- **database/migrations/2026_10_04_000001_expand_catalog_material_fields.php** — Database schema change described by 2026_10_04_000001_expand_catalog_material_fields.php.
- **database/migrations/2026_10_04_000002_add_terminal_reservation_statuses.php** — Database schema change described by 2026_10_04_000002_add_terminal_reservation_statuses.php.
- **database/migrations/2026_10_04_000002_create_registration_verification_codes.php** — Database schema change described by 2026_10_04_000002_create_registration_verification_codes.php.
- **database/migrations/2026_10_05_000001_add_processed_room_image_metadata.php** — Database schema change described by 2026_10_05_000001_add_processed_room_image_metadata.php.
- **database/migrations/2026_10_05_000002_add_room_preview_placement_json.php** — Database schema change described by 2026_10_05_000002_add_room_preview_placement_json.php.
- **database/migrations/2026_10_06_000001_create_room_preview_analyses_table.php** — Database schema change described by 2026_10_06_000001_create_room_preview_analyses_table.php.
- **database/migrations/2026_10_06_000002_extend_room_preview_generations_for_v2.php** — Database schema change described by 2026_10_06_000002_extend_room_preview_generations_for_v2.php.
- **database/migrations/2026_10_06_000003_add_birthdate_and_gender_to_users_table.php** — Database schema change described by 2026_10_06_000003_add_birthdate_and_gender_to_users_table.php.
- **database/migrations/2026_10_07_000001_add_dimensions_estimated_to_furniture_products_table.php** — Database schema change described by 2026_10_07_000001_add_dimensions_estimated_to_furniture_products_table.php.
- **database/migrations/2026_10_27_000001_create_feedback_reports_table.php** — Database schema change described by 2026_10_27_000001_create_feedback_reports_table.php.
- **database/migrations/2026_10_27_000002_add_beta_query_indexes.php** — Database schema change described by 2026_10_27_000002_add_beta_query_indexes.php.
- **database/migrations/2026_10_27_000003_add_feedback_retention_fields.php** — Database schema change described by 2026_10_27_000003_add_feedback_retention_fields.php.
- **database/migrations/2026_11_01_000001_add_notification_dispatch_recovery.php** — Database schema change described by 2026_11_01_000001_add_notification_dispatch_recovery.php.
- **database/migrations/2026_11_01_000002_create_pending_file_deletions.php** — Database schema change described by 2026_11_01_000002_create_pending_file_deletions.php.
- **database/migrations/2026_11_01_000003_create_ai_usage_buckets.php** — Database schema change described by 2026_11_01_000003_create_ai_usage_buckets.php.
- **database/migrations/2026_11_05_000001_add_api_spending_controls.php** — Database schema change described by 2026_11_05_000001_add_api_spending_controls.php.
- **database/migrations/2026_11_14_000001_remove_reservation_receipt_emails.php** — Database schema change described by 2026_11_14_000001_remove_reservation_receipt_emails.php.
- **database/migrations/2026_11_18_000001_add_preview_attempt_reservations.php** — Database schema change described by 2026_11_18_000001_add_preview_attempt_reservations.php.
- **database/migrations/2026_11_20_000001_repair_legacy_xampp_users_table.php** — Database schema change described by 2026_11_20_000001_repair_legacy_xampp_users_table.php.
- **database/seeders/BetaTestAccountsSeeder.php** — Seeds initial, catalog or test records using BetaTestAccountsSeeder.php.
- **database/seeders/ConsoleVanityCollectionSeeder.php** — Seeds initial, catalog or test records using ConsoleVanityCollectionSeeder.php.
- **database/seeders/CustomerCatalogProductsSeeder.php** — Seeds initial, catalog or test records using CustomerCatalogProductsSeeder.php.
- **database/seeders/CustomizedCurvingDoorsSeeder.php** — Seeds initial, catalog or test records using CustomizedCurvingDoorsSeeder.php.
- **database/seeders/DatabaseSeeder.php** — Seeds initial, catalog or test records using DatabaseSeeder.php.
- **database/seeders/DiningCollectionSeeder.php** — Seeds initial, catalog or test records using DiningCollectionSeeder.php.
- **database/seeders/NamedFurnitureCollectionSeeder.php** — Seeds initial, catalog or test records using NamedFurnitureCollectionSeeder.php.
- **database/seeders/SalaCollectionSeeder.php** — Seeds initial, catalog or test records using SalaCollectionSeeder.php.

### DEPLOYMENT_CHECKLIST.md/

- **DEPLOYMENT_CHECKLIST.md** — Project configuration or support file used by the application or developer tooling.

### DEPLOYMENT.md/

- **DEPLOYMENT.md** — Project configuration or support file used by the application or developer tooling.

### docs/

- **docs/api-cost-controls.md** — Technical guide or project reference artifact: api-cost-controls.md.
- **docs/artifacts/alpha-test-role-flowcharts.docx** — Technical guide or project reference artifact: alpha-test-role-flowcharts.docx.
- **docs/artifacts/furnicraft-erd-alpha.pdf** — Technical guide or project reference artifact: furnicraft-erd-alpha.pdf.
- **docs/artifacts/furnicraft-erd-alpha.png** — Technical guide or project reference artifact: furnicraft-erd-alpha.png.
- **docs/artifacts/furnicraft-erd-alpha.svg** — Technical guide or project reference artifact: furnicraft-erd-alpha.svg.
- **docs/artifacts/pdf/FurniCraft_Alpha_Testing_Document.pdf** — Technical guide or project reference artifact: FurniCraft_Alpha_Testing_Document.pdf.
- **docs/beta-hardening.md** — Technical guide or project reference artifact: beta-hardening.md.
- **docs/brand-assets.md** — Technical guide or project reference artifact: brand-assets.md.
- **docs/browser-acceptance.md** — Technical guide or project reference artifact: browser-acceptance.md.
- **docs/design-system.md** — Technical guide or project reference artifact: design-system.md.
- **docs/local-database-readiness.md** — Technical guide or project reference artifact: local-database-readiness.md.
- **docs/room-preview-v2.md** — Technical guide or project reference artifact: room-preview-v2.md.
- **docs/security-hardening.md** — Technical guide or project reference artifact: security-hardening.md.

### output/

- **output/docx/Beta-Test-Evaluation-Form1-DEVSQUAD-completed.docx** — Generated output artifact; not live application logic.
- **output/imagegen/bed-floral-medallion/bed_full-draft.png** — Generated output artifact; not live application logic.
- **output/imagegen/bed-openwork-floral/bed_full-draft.png** — Generated output artifact; not live application logic.
- **output/imagegen/bed-set-shell/bed-set_full-draft.png** — Generated output artifact; not live application logic.
- **output/imagegen/bed-shell-closeup/bed_full-draft.png** — Generated output artifact; not live application logic.
- **output/imagegen/carved-door-floral-vase/door_full-draft.png** — Generated output artifact; not live application logic.
- **output/imagegen/carved-door-rosette/door_full-draft.png** — Generated output artifact; not live application logic.
- **output/imagegen/carved-door-scroll/door_full-draft.png** — Generated output artifact; not live application logic.
- **output/imagegen/console-mirror/console-mirror_full-draft.png** — Generated output artifact; not live application logic.
- **output/imagegen/dining-set-20261118/dining-set_full.png** — Generated output artifact; not live application logic.
- **output/imagegen/sala-set-801581578/sala-set_full.png** — Generated output artifact; not live application logic.
- **output/imagegen/sala-set-801581578/sala-set_meta.json** — Generated output artifact; not live application logic.
- **output/imagegen/sala-set-home-20261118/sala-set_full.png** — Generated output artifact; not live application logic.
- **output/imagegen/storage-bed-carved-medallions/bed-set_full-draft.png** — Generated output artifact; not live application logic.
- **output/imagegen/storage-bed-oval/bed-set_full-draft-v2.png** — Generated output artifact; not live application logic.
- **output/imagegen/storage-bed-oval/bed-set_full-draft.png** — Generated output artifact; not live application logic.
- **output/imagegen/vanity-carved/vanity-set_full-draft.png** — Generated output artifact; not live application logic.
- **output/pdf/Beta-Test-Evaluation-Form-DEVSQUAD-filled.pdf** — Generated output artifact; not live application logic.
- **output/pdf/Beta-Test-Evaluation-Form1-DEVSQUAD-completed.pdf** — Generated output artifact; not live application logic.
- **output/pdf/CAYABYAB_ASHLEY_ITE04_PREFINAL-LAB-ACTIVITY-clear-under-10MB.pdf** — Generated output artifact; not live application logic.
- **output/pdf/CAYABYAB_ASHLEY_ITE04_PREFINAL-LAB-ACTIVITY-under-10MB.pdf** — Generated output artifact; not live application logic.

### package-lock.json/

- **package-lock.json** — Project configuration or support file used by the application or developer tooling.

### package.json/

- **package.json** — Project configuration or support file used by the application or developer tooling.

### phpunit.xml/

- **phpunit.xml** — Project configuration or support file used by the application or developer tooling.

### playwright.config.js/

- **playwright.config.js** — Project configuration or support file used by the application or developer tooling.

### public/

- **public/css/furnicraft.css** — Public stylesheet for site layout, visual design and responsive behavior.
- **public/images/auth-background-natural.png** — Brand, optimized image, icon or interface background asset: auth-background-natural.png.
- **public/images/catalog/catalog-3-pad.png** — Furniture product image displayed in the catalog: catalog-3-pad.png.
- **public/images/catalog/catalog-605.png** — Furniture product image displayed in the catalog: catalog-605.png.
- **public/images/catalog/catalog-7-panel-door-cutout.png** — Furniture product image displayed in the catalog: catalog-7-panel-door-cutout.png.
- **public/images/catalog/catalog-alon-two-tone-panel-sala-set.png** — Furniture product image displayed in the catalog: catalog-alon-two-tone-panel-sala-set.png.
- **public/images/catalog/catalog-arko-curved-back-dining-set.png** — Furniture product image displayed in the catalog: catalog-arko-curved-back-dining-set.png.
- **public/images/catalog/catalog-arko-dark-glass-dining-set.png** — Furniture product image displayed in the catalog: catalog-arko-dark-glass-dining-set.png.
- **public/images/catalog/catalog-balangay-classic-sala-set.png** — Furniture product image displayed in the catalog: catalog-balangay-classic-sala-set.png.
- **public/images/catalog/catalog-balwarte-two-tone-arch-sala-set.png** — Furniture product image displayed in the catalog: catalog-balwarte-two-tone-arch-sala-set.png.
- **public/images/catalog/catalog-bedframe-bricks-cutout.png** — Furniture product image displayed in the catalog: catalog-bedframe-bricks-cutout.png.
- **public/images/catalog/catalog-bedframe-flower-full-curving-cutout.png** — Furniture product image displayed in the catalog: catalog-bedframe-flower-full-curving-cutout.png.
- **public/images/catalog/catalog-bedframe-heart-curving.png** — Furniture product image displayed in the catalog: catalog-bedframe-heart-curving.png.
- **public/images/catalog/catalog-channel.png** — Furniture product image displayed in the catalog: catalog-channel.png.
- **public/images/catalog/catalog-chinese-double-deck-drawer-cutout.png** — Furniture product image displayed in the catalog: catalog-chinese-double-deck-drawer-cutout.png.
- **public/images/catalog/catalog-console.png** — Furniture product image displayed in the catalog: catalog-console.png.
- **public/images/catalog/catalog-custom-floral-vase-door.png** — Furniture product image displayed in the catalog: catalog-custom-floral-vase-door.png.
- **public/images/catalog/catalog-custom-heritage-scroll-door.png** — Furniture product image displayed in the catalog: catalog-custom-heritage-scroll-door.png.
- **public/images/catalog/catalog-custom-royal-rosette-door.png** — Furniture product image displayed in the catalog: catalog-custom-royal-rosette-door.png.
- **public/images/catalog/catalog-dalisay-cream-cushion-sala-set.png** — Furniture product image displayed in the catalog: catalog-dalisay-cream-cushion-sala-set.png.
- **public/images/catalog/catalog-dalisay-white-two-tone-dining-set.png** — Furniture product image displayed in the catalog: catalog-dalisay-white-two-tone-dining-set.png.
- **public/images/catalog/catalog-dapit-black-and-amber-dining-set.png** — Furniture product image displayed in the catalog: catalog-dapit-black-and-amber-dining-set.png.
- **public/images/catalog/catalog-dapit-dark-slatted-sala-set.png** — Furniture product image displayed in the catalog: catalog-dapit-dark-slatted-sala-set.png.
- **public/images/catalog/catalog-engraved-door.png** — Furniture product image displayed in the catalog: catalog-engraved-door.png.
- **public/images/catalog/catalog-guhit-amber-and-black-dining-set.png** — Furniture product image displayed in the catalog: catalog-guhit-amber-and-black-dining-set.png.
- **public/images/catalog/catalog-guhit-gold-inlay-dining-set.png** — Furniture product image displayed in the catalog: catalog-guhit-gold-inlay-dining-set.png.
- **public/images/catalog/catalog-habi-basketweave-sala-set.png** — Furniture product image displayed in the catalog: catalog-habi-basketweave-sala-set.png.
- **public/images/catalog/catalog-hagdan-black-accent-dining-set.png** — Furniture product image displayed in the catalog: catalog-hagdan-black-accent-dining-set.png.
- **public/images/catalog/catalog-hagdan-classic-dining-set.png** — Furniture product image displayed in the catalog: catalog-hagdan-classic-dining-set.png.
- **public/images/catalog/catalog-haligi-black-slat-dining-set.png** — Furniture product image displayed in the catalog: catalog-haligi-black-slat-dining-set.png.
- **public/images/catalog/catalog-haligi-classic-console-and-mirror-set-updated.png** — Furniture product image displayed in the catalog: catalog-haligi-classic-console-and-mirror-set-updated.png.
- **public/images/catalog/catalog-haligi-dark-accent-dining-set.png** — Furniture product image displayed in the catalog: catalog-haligi-dark-accent-dining-set.png.
- **public/images/catalog/catalog-hapon-gray-cushion-sala-set.png** — Furniture product image displayed in the catalog: catalog-hapon-gray-cushion-sala-set.png.
- **public/images/catalog/catalog-hiraya-classic-dining-set.png** — Furniture product image displayed in the catalog: catalog-hiraya-classic-dining-set.png.
- **public/images/catalog/catalog-hiraya-ladder-back-dining-set.png** — Furniture product image displayed in the catalog: catalog-hiraya-ladder-back-dining-set.png.
- **public/images/catalog/catalog-hiraya-slatted-sala-set.png** — Furniture product image displayed in the catalog: catalog-hiraya-slatted-sala-set.png.
- **public/images/catalog/catalog-imperial-scroll-console-and-mirror-set-updated.png** — Furniture product image displayed in the catalog: catalog-imperial-scroll-console-and-mirror-set-updated.png.
- **public/images/catalog/catalog-imperial-shell-crest-sala-set.png** — Furniture product image displayed in the catalog: catalog-imperial-shell-crest-sala-set.png.
- **public/images/catalog/catalog-indian-door.png** — Furniture product image displayed in the catalog: catalog-indian-door.png.
- **public/images/catalog/catalog-linyang-cream-inlay-dining-set.png** — Furniture product image displayed in the catalog: catalog-linyang-cream-inlay-dining-set.png.
- **public/images/catalog/catalog-liwanag-cream-slat-sala-set.png** — Furniture product image displayed in the catalog: catalog-liwanag-cream-slat-sala-set.png.
- **public/images/catalog/catalog-luna-round-mirror-console-set-updated.png** — Furniture product image displayed in the catalog: catalog-luna-round-mirror-console-set-updated.png.
- **public/images/catalog/catalog-marilag-carved-storage-sala-set.png** — Furniture product image displayed in the catalog: catalog-marilag-carved-storage-sala-set.png.
- **public/images/catalog/catalog-mosaiko-brick-pattern-sala-set.png** — Furniture product image displayed in the catalog: catalog-mosaiko-brick-pattern-sala-set.png.
- **public/images/catalog/catalog-mutya-fan-back-curving-sala-set.png** — Furniture product image displayed in the catalog: catalog-mutya-fan-back-curving-sala-set.png.
- **public/images/catalog/catalog-pahinga-cream-sectional-sala-set.png** — Furniture product image displayed in the catalog: catalog-pahinga-cream-sectional-sala-set.png.
- **public/images/catalog/catalog-pamana-floral-vase-sala-set.png** — Furniture product image displayed in the catalog: catalog-pamana-floral-vase-sala-set.png.
- **public/images/catalog/catalog-perlas-shell-curving-sala-set.png** — Furniture product image displayed in the catalog: catalog-perlas-shell-curving-sala-set.png.
- **public/images/catalog/catalog-piano-lining-black.png** — Furniture product image displayed in the catalog: catalog-piano-lining-black.png.
- **public/images/catalog/catalog-piano-s-diamond.png** — Furniture product image displayed in the catalog: catalog-piano-s-diamond.png.
- **public/images/catalog/catalog-piano-with-s.png** — Furniture product image displayed in the catalog: catalog-piano-with-s.png.
- **public/images/catalog/catalog-piging-gloss-dining-set.png** — Furniture product image displayed in the catalog: catalog-piging-gloss-dining-set.png.
- **public/images/catalog/catalog-pulang-rosas-carved-sala-set.png** — Furniture product image displayed in the catalog: catalog-pulang-rosas-carved-sala-set.png.
- **public/images/catalog/catalog-reyna-curving-vanity-set-updated.png** — Furniture product image displayed in the catalog: catalog-reyna-curving-vanity-set-updated.png.
- **public/images/catalog/catalog-rosas-pattern-cushion-sala-set.png** — Furniture product image displayed in the catalog: catalog-rosas-pattern-cushion-sala-set.png.
- **public/images/catalog/catalog-royal-blossom-console-and-mirror-set-updated.png** — Furniture product image displayed in the catalog: catalog-royal-blossom-console-and-mirror-set-updated.png.
- **public/images/catalog/catalog-royal-crest-vanity-set-updated.png** — Furniture product image displayed in the catalog: catalog-royal-crest-vanity-set-updated.png.
- **public/images/catalog/catalog-royal-flora-curving-dining-set.png** — Furniture product image displayed in the catalog: catalog-royal-flora-curving-dining-set.png.
- **public/images/catalog/catalog-royal-garden-medallion-sala-set.png** — Furniture product image displayed in the catalog: catalog-royal-garden-medallion-sala-set.png.
- **public/images/catalog/catalog-royal-shell-lattice-sala-set.png** — Furniture product image displayed in the catalog: catalog-royal-shell-lattice-sala-set.png.
- **public/images/catalog/catalog-royal-sunflower-gold-accent-sala-set.png** — Furniture product image displayed in the catalog: catalog-royal-sunflower-gold-accent-sala-set.png.
- **public/images/catalog/catalog-royal-swan-curving-sala-set.png** — Furniture product image displayed in the catalog: catalog-royal-swan-curving-sala-set.png.
- **public/images/catalog/catalog-salo-rounded-corner-dining-set.png** — Furniture product image displayed in the catalog: catalog-salo-rounded-corner-dining-set.png.
- **public/images/catalog/catalog-sampaguita-floral-curving-bed-frame.png** — Furniture product image displayed in the catalog: catalog-sampaguita-floral-curving-bed-frame.png.
- **public/images/catalog/catalog-silahis-gold-panel-dining-set.png** — Furniture product image displayed in the catalog: catalog-silahis-gold-panel-dining-set.png.
- **public/images/catalog/catalog-silayan-two-tone-cushion-sala-set.png** — Furniture product image displayed in the catalog: catalog-silayan-two-tone-cushion-sala-set.png.
- **public/images/catalog/catalog-silong-vertical-slat-sala-set.png** — Furniture product image displayed in the catalog: catalog-silong-vertical-slat-sala-set.png.
- **public/images/catalog/catalog-sinag-vertical-slat-dining-set.png** — Furniture product image displayed in the catalog: catalog-sinag-vertical-slat-dining-set.png.
- **public/images/catalog/catalog-sinag-yellow-cushion-sala-set.png** — Furniture product image displayed in the catalog: catalog-sinag-yellow-cushion-sala-set.png.
- **public/images/catalog/catalog-sinta-heart-curving-sala-set.png** — Furniture product image displayed in the catalog: catalog-sinta-heart-curving-sala-set.png.
- **public/images/catalog/catalog-special-design-piano-diamond.png** — Furniture product image displayed in the catalog: catalog-special-design-piano-diamond.png.
- **public/images/catalog/catalog-tahanan-rounded-edge-dining-set.png** — Furniture product image displayed in the catalog: catalog-tahanan-rounded-edge-dining-set.png.
- **public/images/catalog/catalog-tahanan-six-drawer-dresser-with-mirror-updated.png** — Furniture product image displayed in the catalog: catalog-tahanan-six-drawer-dresser-with-mirror-updated.png.
- **public/images/catalog/catalog-taiwan-double-deck-3-drawer-cutout.png** — Furniture product image displayed in the catalog: catalog-taiwan-double-deck-3-drawer-cutout.png.
- **public/images/catalog/catalog-tindig-slatted-storage-sala-set.png** — Furniture product image displayed in the catalog: catalog-tindig-slatted-storage-sala-set.png.
- **public/images/catalog/catalog-ulap-gray-sectional-sala-set.png** — Furniture product image displayed in the catalog: catalog-ulap-gray-sectional-sala-set.png.
- **public/images/catalog/catalog-yakap-cream-cushion-dining-set.png** — Furniture product image displayed in the catalog: catalog-yakap-cream-cushion-dining-set.png.
- **public/images/catalog/drive-01.png** — Furniture product image displayed in the catalog: drive-01.png.
- **public/images/catalog/drive-02.png** — Furniture product image displayed in the catalog: drive-02.png.
- **public/images/catalog/drive-03.png** — Furniture product image displayed in the catalog: drive-03.png.
- **public/images/catalog/drive-04.png** — Furniture product image displayed in the catalog: drive-04.png.
- **public/images/catalog/drive-05.png** — Furniture product image displayed in the catalog: drive-05.png.
- **public/images/catalog/drive-06.png** — Furniture product image displayed in the catalog: drive-06.png.
- **public/images/catalog/drive-07.png** — Furniture product image displayed in the catalog: drive-07.png.
- **public/images/catalog/drive-08.png** — Furniture product image displayed in the catalog: drive-08.png.
- **public/images/catalog/drive-09.png** — Furniture product image displayed in the catalog: drive-09.png.
- **public/images/furniture/bed.svg** — Brand, optimized image, icon or interface background asset: bed.svg.
- **public/images/furniture/cabinet.svg** — Brand, optimized image, icon or interface background asset: cabinet.svg.
- **public/images/furniture/chair.svg** — Brand, optimized image, icon or interface background asset: chair.svg.
- **public/images/furniture/default.svg** — Brand, optimized image, icon or interface background asset: default.svg.
- **public/images/furniture/dining.svg** — Brand, optimized image, icon or interface background asset: dining.svg.
- **public/images/furniture/door.svg** — Brand, optimized image, icon or interface background asset: door.svg.
- **public/images/furniture/table.svg** — Brand, optimized image, icon or interface background asset: table.svg.
- **public/images/home-background-natural.png** — Brand, optimized image, icon or interface background asset: home-background-natural.png.
- **public/images/how-it-works-background-natural.png** — Brand, optimized image, icon or interface background asset: how-it-works-background-natural.png.
- **public/images/lance-favicon-circle.png** — Brand, optimized image, icon or interface background asset: lance-favicon-circle.png.
- **public/images/lance-furniture-shop-logo-natural.png** — Brand, optimized image, icon or interface background asset: lance-furniture-shop-logo-natural.png.
- **public/images/optimized/auth-background.avif** — Brand, optimized image, icon or interface background asset: auth-background.avif.
- **public/images/optimized/auth-background.jpg** — Brand, optimized image, icon or interface background asset: auth-background.jpg.
- **public/images/optimized/auth-background.webp** — Brand, optimized image, icon or interface background asset: auth-background.webp.
- **public/images/optimized/favorites-background-sage.jpg** — Brand, optimized image, icon or interface background asset: favorites-background-sage.jpg.
- **public/images/optimized/favorites-background-sage.webp** — Brand, optimized image, icon or interface background asset: favorites-background-sage.webp.
- **public/images/optimized/home-background.avif** — Brand, optimized image, icon or interface background asset: home-background.avif.
- **public/images/optimized/home-background.jpg** — Brand, optimized image, icon or interface background asset: home-background.jpg.
- **public/images/optimized/home-background.webp** — Brand, optimized image, icon or interface background asset: home-background.webp.
- **public/images/optimized/how-it-works-background.jpg** — Brand, optimized image, icon or interface background asset: how-it-works-background.jpg.
- **public/images/optimized/how-it-works-background.webp** — Brand, optimized image, icon or interface background asset: how-it-works-background.webp.
- **public/images/optimized/lance-apple-touch-180.png** — Brand, optimized image, icon or interface background asset: lance-apple-touch-180.png.
- **public/images/optimized/lance-favicon-32.png** — Brand, optimized image, icon or interface background asset: lance-favicon-32.png.
- **public/images/optimized/lance-favicon-48.png** — Brand, optimized image, icon or interface background asset: lance-favicon-48.png.
- **public/images/optimized/lance-logo-128.webp** — Brand, optimized image, icon or interface background asset: lance-logo-128.webp.
- **public/images/optimized/lance-logo-64.webp** — Brand, optimized image, icon or interface background asset: lance-logo-64.webp.
- **public/images/optimized/lance-logo-circle-128.webp** — Brand, optimized image, icon or interface background asset: lance-logo-circle-128.webp.
- **public/images/optimized/lance-logo-circle-64.webp** — Brand, optimized image, icon or interface background asset: lance-logo-circle-64.webp.
- **public/images/optimized/reservations-background.jpg** — Brand, optimized image, icon or interface background asset: reservations-background.jpg.
- **public/images/optimized/reservations-background.webp** — Brand, optimized image, icon or interface background asset: reservations-background.webp.
- **public/images/reservations-background-natural.png** — Brand, optimized image, icon or interface background asset: reservations-background-natural.png.
- **public/images/reservations-background-sage.png** — Brand, optimized image, icon or interface background asset: reservations-background-sage.png.
- **public/index.php** — Project configuration or support file used by the application or developer tooling.
- **public/js/furnicraft.js** — Browser-side JavaScript for FurniCraft interactions: furnicraft.js.
- **public/js/room-preview-engine.js** — Browser-side JavaScript for FurniCraft interactions: room-preview-engine.js.
- **public/robots.txt** — Project configuration or support file used by the application or developer tooling.

### README.md/

- **README.md** — Project configuration or support file used by the application or developer tooling.

### resources/

- **resources/css/app.css** — Project configuration or support file used by the application or developer tooling.
- **resources/js/app.js** — Project configuration or support file used by the application or developer tooling.
- **resources/js/bootstrap.js** — Project configuration or support file used by the application or developer tooling.
- **resources/js/home-carousel.js** — Project configuration or support file used by the application or developer tooling.
- **resources/views/admin/api-usage.blade.php** — Blade page or reusable interface partial for api-usage.blade.php.
- **resources/views/admin/customers/index.blade.php** — Blade page or reusable interface partial for index.blade.php.
- **resources/views/admin/dashboard.blade.php** — Blade page or reusable interface partial for dashboard.blade.php.
- **resources/views/admin/privacy-audit.blade.php** — Blade page or reusable interface partial for privacy-audit.blade.php.
- **resources/views/admin/products/_form-section.blade.php** — Blade page or reusable interface partial for _form-section.blade.php.
- **resources/views/admin/products/_form.blade.php** — Blade page or reusable interface partial for _form.blade.php.
- **resources/views/admin/products/create.blade.php** — Blade page or reusable interface partial for create.blade.php.
- **resources/views/admin/products/edit.blade.php** — Blade page or reusable interface partial for edit.blade.php.
- **resources/views/admin/products/index.blade.php** — Blade page or reusable interface partial for index.blade.php.
- **resources/views/admin/reports/index.blade.php** — Blade page or reusable interface partial for index.blade.php.
- **resources/views/admin/reports/pdf.blade.php** — Blade page or reusable interface partial for pdf.blade.php.
- **resources/views/admin/reservations/index.blade.php** — Blade page or reusable interface partial for index.blade.php.
- **resources/views/admin/reservations/preview.blade.php** — Blade page or reusable interface partial for preview.blade.php.
- **resources/views/admin/room-placement-evaluation.blade.php** — Blade page or reusable interface partial for room-placement-evaluation.blade.php.
- **resources/views/auth/forgot-password.blade.php** — Blade page or reusable interface partial for forgot-password.blade.php.
- **resources/views/auth/login.blade.php** — Blade page or reusable interface partial for login.blade.php.
- **resources/views/auth/register.blade.php** — Blade page or reusable interface partial for register.blade.php.
- **resources/views/auth/reset-password.blade.php** — Blade page or reusable interface partial for reset-password.blade.php.
- **resources/views/auth/verify-email.blade.php** — Blade page or reusable interface partial for verify-email.blade.php.
- **resources/views/catalog/favorites.blade.php** — Blade page or reusable interface partial for favorites.blade.php.
- **resources/views/catalog/index.blade.php** — Blade page or reusable interface partial for index.blade.php.
- **resources/views/catalog/show.blade.php** — Blade page or reusable interface partial for show.blade.php.
- **resources/views/components/basic-image-upload.blade.php** — Blade page or reusable interface partial for basic-image-upload.blade.php.
- **resources/views/components/product-card.blade.php** — Blade page or reusable interface partial for product-card.blade.php.
- **resources/views/components/search-field.blade.php** — Blade page or reusable interface partial for search-field.blade.php.
- **resources/views/components/workflow-progress.blade.php** — Blade page or reusable interface partial for workflow-progress.blade.php.
- **resources/views/emails/customer-notification-text.blade.php** — Blade page or reusable interface partial for customer-notification-text.blade.php.
- **resources/views/emails/customer-notification.blade.php** — Blade page or reusable interface partial for customer-notification.blade.php.
- **resources/views/emails/feedback-received-text.blade.php** — Blade page or reusable interface partial for feedback-received-text.blade.php.
- **resources/views/emails/feedback-received.blade.php** — Blade page or reusable interface partial for feedback-received.blade.php.
- **resources/views/errors/403.blade.php** — Blade page or reusable interface partial for 403.blade.php.
- **resources/views/errors/404.blade.php** — Blade page or reusable interface partial for 404.blade.php.
- **resources/views/errors/419.blade.php** — Blade page or reusable interface partial for 419.blade.php.
- **resources/views/errors/429.blade.php** — Blade page or reusable interface partial for 429.blade.php.
- **resources/views/errors/500.blade.php** — Blade page or reusable interface partial for 500.blade.php.
- **resources/views/errors/503.blade.php** — Blade page or reusable interface partial for 503.blade.php.
- **resources/views/errors/layout.blade.php** — Blade page or reusable interface partial for layout.blade.php.
- **resources/views/feedback/create.blade.php** — Blade page or reusable interface partial for create.blade.php.
- **resources/views/home.blade.php** — Blade page or reusable interface partial for home.blade.php.
- **resources/views/how-it-works.blade.php** — Blade page or reusable interface partial for how-it-works.blade.php.
- **resources/views/layouts/app.blade.php** — Blade page or reusable interface partial for app.blade.php.
- **resources/views/preferences/_room-option.blade.php** — Blade page or reusable interface partial for _room-option.blade.php.
- **resources/views/preferences/edit.blade.php** — Blade page or reusable interface partial for edit.blade.php.
- **resources/views/privacy-policy.blade.php** — Blade page or reusable interface partial for privacy-policy.blade.php.
- **resources/views/profile/edit.blade.php** — Blade page or reusable interface partial for edit.blade.php.
- **resources/views/recommendations/_chat.blade.php** — Blade page or reusable interface partial for _chat.blade.php.
- **resources/views/recommendations/_floating-chat.blade.php** — Blade page or reusable interface partial for _floating-chat.blade.php.
- **resources/views/recommendations/index.blade.php** — Blade page or reusable interface partial for index.blade.php.
- **resources/views/recommendations/show.blade.php** — Blade page or reusable interface partial for show.blade.php.
- **resources/views/reservations/_customization-fields.blade.php** — Blade page or reusable interface partial for _customization-fields.blade.php.
- **resources/views/reservations/index.blade.php** — Blade page or reusable interface partial for index.blade.php.
- **resources/views/room/index.blade.php** — Blade page or reusable interface partial for index.blade.php.
- **resources/views/room/visualize.blade.php** — Blade page or reusable interface partial for visualize.blade.php.
- **resources/views/welcome.blade.php** — Blade page or reusable interface partial for welcome.blade.php.

### routes/

- **routes/console.php** — Console command and scheduled-task route definitions.
- **routes/web.php** — HTTP route map linking website URLs to controllers and middleware.

### start-queue-worker.bat/

- **start-queue-worker.bat** — Project configuration or support file used by the application or developer tooling.

### start-scheduler.bat/

- **start-scheduler.bat** — Project configuration or support file used by the application or developer tooling.

### start-system.bat/

- **start-system.bat** — Project configuration or support file used by the application or developer tooling.

### start-system.ps1/

- **start-system.ps1** — Project configuration or support file used by the application or developer tooling.

### start-web-server.bat/

- **start-web-server.bat** — Project configuration or support file used by the application or developer tooling.

### storage/

- **storage/app/.gitignore** — Laravel runtime storage placeholder or mail support file.
- **storage/app/private/.gitignore** — Laravel runtime storage placeholder or mail support file.
- **storage/app/public/.gitignore** — Laravel runtime storage placeholder or mail support file.
- **storage/certs/mail-ca-bundle.pem** — Laravel runtime storage placeholder or mail support file.
- **storage/certs/mail-ca.ini** — Laravel runtime storage placeholder or mail support file.
- **storage/framework/cache/.gitignore** — Laravel runtime storage placeholder or mail support file.
- **storage/framework/cache/data/.gitignore** — Laravel runtime storage placeholder or mail support file.
- **storage/framework/sessions/.gitignore** — Laravel runtime storage placeholder or mail support file.
- **storage/framework/testing/.gitignore** — Laravel runtime storage placeholder or mail support file.
- **storage/framework/views/.gitignore** — Laravel runtime storage placeholder or mail support file.
- **storage/logs/.gitignore** — Laravel runtime storage placeholder or mail support file.

### tests/

- **tests/Browser/automatic-room-fit.spec.js** — Browser test or setup fixture for the automatic-room-fit.spec.js user flow.
- **tests/Browser/customer-mobile.spec.js** — Browser test or setup fixture for the customer-mobile.spec.js user flow.
- **tests/Browser/global-setup.js** — Browser test or setup fixture for the global-setup.js user flow.
- **tests/Browser/issue-reset-fixture.php** — Browser test or setup fixture for the issue-reset-fixture.php user flow.
- **tests/Browser/password-security.spec.js** — Browser test or setup fixture for the password-security.spec.js user flow.
- **tests/Browser/public-pages.spec.js** — Browser test or setup fixture for the public-pages.spec.js user flow.
- **tests/Browser/reservation-cancellation.spec.js** — Browser test or setup fixture for the reservation-cancellation.spec.js user flow.
- **tests/Browser/seed-mobile.php** — Browser test or setup fixture for the seed-mobile.php user flow.
- **tests/Feature/AccountDeactivationTest.php** — Automated feature regression test for AccountDeactivationTest.php behavior.
- **tests/Feature/AdminAccessibilityTest.php** — Automated feature regression test for AdminAccessibilityTest.php behavior.
- **tests/Feature/AdminAggregationTest.php** — Automated feature regression test for AdminAggregationTest.php behavior.
- **tests/Feature/AdminApiUsagePageTest.php** — Automated feature regression test for AdminApiUsagePageTest.php behavior.
- **tests/Feature/AdminReservationPreviewTest.php** — Automated feature regression test for AdminReservationPreviewTest.php behavior.
- **tests/Feature/AiRequestBudgetTest.php** — Automated feature regression test for AiRequestBudgetTest.php behavior.
- **tests/Feature/ApiSpendingProtectionTest.php** — Automated feature regression test for ApiSpendingProtectionTest.php behavior.
- **tests/Feature/AutomaticPlacementSafetyTest.php** — Automated feature regression test for AutomaticPlacementSafetyTest.php behavior.
- **tests/Feature/AutomaticPreviewReliabilityTest.php** — Automated feature regression test for AutomaticPreviewReliabilityTest.php behavior.
- **tests/Feature/AutomaticRoomFittingPipelineTest.php** — Automated feature regression test for AutomaticRoomFittingPipelineTest.php behavior.
- **tests/Feature/BetaSupportTest.php** — Automated feature regression test for BetaSupportTest.php behavior.
- **tests/Feature/BrandedEmailDesignTest.php** — Automated feature regression test for BrandedEmailDesignTest.php behavior.
- **tests/Feature/BudgetRangeTest.php** — Automated feature regression test for BudgetRangeTest.php behavior.
- **tests/Feature/CatalogCategoryOptionsTest.php** — Automated feature regression test for CatalogCategoryOptionsTest.php behavior.
- **tests/Feature/CatalogFinishFilterTest.php** — Automated feature regression test for CatalogFinishFilterTest.php behavior.
- **tests/Feature/CatalogInputValidationTest.php** — Automated feature regression test for CatalogInputValidationTest.php behavior.
- **tests/Feature/CatalogSearchFieldConsistencyTest.php** — Automated feature regression test for CatalogSearchFieldConsistencyTest.php behavior.
- **tests/Feature/CatalogViewDeduplicationTest.php** — Automated feature regression test for CatalogViewDeduplicationTest.php behavior.
- **tests/Feature/CreateAdminAccountTest.php** — Automated feature regression test for CreateAdminAccountTest.php behavior.
- **tests/Feature/CustomerNavigationTest.php** — Automated feature regression test for CustomerNavigationTest.php behavior.
- **tests/Feature/CustomerSearchTest.php** — Automated feature regression test for CustomerSearchTest.php behavior.
- **tests/Feature/DeploymentChecklistTest.php** — Automated feature regression test for DeploymentChecklistTest.php behavior.
- **tests/Feature/EmailVerificationCodeNotificationTest.php** — Automated feature regression test for EmailVerificationCodeNotificationTest.php behavior.
- **tests/Feature/ExampleTest.php** — Automated feature regression test for ExampleTest.php behavior.
- **tests/Feature/FavoritesTest.php** — Automated feature regression test for FavoritesTest.php behavior.
- **tests/Feature/FeedbackRetentionTest.php** — Automated feature regression test for FeedbackRetentionTest.php behavior.
- **tests/Feature/GeminiOnlyProviderTest.php** — Automated feature regression test for GeminiOnlyProviderTest.php behavior.
- **tests/Feature/LoginInputValidationTest.php** — Automated feature regression test for LoginInputValidationTest.php behavior.
- **tests/Feature/MobileNumberValidationTest.php** — Automated feature regression test for MobileNumberValidationTest.php behavior.
- **tests/Feature/NamedRateLimitTest.php** — Automated feature regression test for NamedRateLimitTest.php behavior.
- **tests/Feature/NotificationDeliveryLogTest.php** — Automated feature regression test for NotificationDeliveryLogTest.php behavior.
- **tests/Feature/OperationsMonitorTest.php** — Automated feature regression test for OperationsMonitorTest.php behavior.
- **tests/Feature/PasswordRecoverySecurityTest.php** — Automated feature regression test for PasswordRecoverySecurityTest.php behavior.
- **tests/Feature/PhotoOnlyRoomFittingTest.php** — Automated feature regression test for PhotoOnlyRoomFittingTest.php behavior.
- **tests/Feature/PlannerCatalogFiltersTest.php** — Automated feature regression test for PlannerCatalogFiltersTest.php behavior.
- **tests/Feature/PreferenceBudgetSafetyTest.php** — Automated feature regression test for PreferenceBudgetSafetyTest.php behavior.
- **tests/Feature/PreviewAspectRatioTest.php** — Automated feature regression test for PreviewAspectRatioTest.php behavior.
- **tests/Feature/PreviewRetryTest.php** — Automated feature regression test for PreviewRetryTest.php behavior.
- **tests/Feature/PreviewVerificationStatusTest.php** — Automated feature regression test for PreviewVerificationStatusTest.php behavior.
- **tests/Feature/PrivacyAuditPageTest.php** — Automated feature regression test for PrivacyAuditPageTest.php behavior.
- **tests/Feature/ProductionReadinessTest.php** — Automated feature regression test for ProductionReadinessTest.php behavior.
- **tests/Feature/PublicCatalogTest.php** — Automated feature regression test for PublicCatalogTest.php behavior.
- **tests/Feature/RecommendationDataLoadingTest.php** — Automated feature regression test for RecommendationDataLoadingTest.php behavior.
- **tests/Feature/RegistrationPrivacyTest.php** — Automated feature regression test for RegistrationPrivacyTest.php behavior.
- **tests/Feature/RegistrationVerificationRetentionTest.php** — Automated feature regression test for RegistrationVerificationRetentionTest.php behavior.
- **tests/Feature/ReportDownloadTest.php** — Automated feature regression test for ReportDownloadTest.php behavior.
- **tests/Feature/ReservationDeletionTest.php** — Automated feature regression test for ReservationDeletionTest.php behavior.
- **tests/Feature/ReservationStatusTimelineTest.php** — Automated feature regression test for ReservationStatusTimelineTest.php behavior.
- **tests/Feature/RoomAnalysisGuardTest.php** — Automated feature regression test for RoomAnalysisGuardTest.php behavior.
- **tests/Feature/RoomDisplayLabelTest.php** — Automated feature regression test for RoomDisplayLabelTest.php behavior.
- **tests/Feature/RoomImageOrientationTest.php** — Automated feature regression test for RoomImageOrientationTest.php behavior.
- **tests/Feature/RoomPhotoRetentionTest.php** — Automated feature regression test for RoomPhotoRetentionTest.php behavior.
- **tests/Feature/RoomPhotoStorageSafetyTest.php** — Automated feature regression test for RoomPhotoStorageSafetyTest.php behavior.
- **tests/Feature/RoomPreviewLightingTest.php** — Automated feature regression test for RoomPreviewLightingTest.php behavior.
- **tests/Feature/RoomPreviewRasterPlacementTest.php** — Automated feature regression test for RoomPreviewRasterPlacementTest.php behavior.
- **tests/Feature/RoomPreviewV2Test.php** — Automated feature regression test for RoomPreviewV2Test.php behavior.
- **tests/Feature/RoomPreviewWorkflowPresentationTest.php** — Automated feature regression test for RoomPreviewWorkflowPresentationTest.php behavior.
- **tests/Feature/RoomUploadSafetyTest.php** — Automated feature regression test for RoomUploadSafetyTest.php behavior.
- **tests/Feature/SecurityAccessAuditTest.php** — Automated feature regression test for SecurityAccessAuditTest.php behavior.
- **tests/Feature/SecurityHardeningTest.php** — Automated feature regression test for SecurityHardeningTest.php behavior.
- **tests/Feature/SeederAccountSafetyTest.php** — Automated feature regression test for SeederAccountSafetyTest.php behavior.
- **tests/Feature/WorkflowRegressionTest.php** — Automated feature regression test for WorkflowRegressionTest.php behavior.
- **tests/Fixtures/doorway-fit-quads.json** — Sample input used to repeat the doorway-fit-quads.json test scenario.
- **tests/Fixtures/room-600.png** — Sample input used to repeat the room-600.png test scenario.
- **tests/Fixtures/room-preview-geometry.json** — Sample input used to repeat the room-preview-geometry.json test scenario.
- **tests/Fixtures/room-preview-scale.json** — Sample input used to repeat the room-preview-scale.json test scenario.
- **tests/room-preview-engine.test.js** — Project configuration or support file used by the application or developer tooling.
- **tests/TestCase.php** — Project configuration or support file used by the application or developer tooling.

### tmp/

- **tmp/pdfs/clear-preview.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/compressed-01.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/compressed-02.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/compressed-03.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/compressed-04.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/compressed-05.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/compressed-06.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/compressed-07.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/compressed-08.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/compressed-09.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/compressed-10.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/final-1.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/lossless.py** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/original-01.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/original-02.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/original-03.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/original-04.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/original-05.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/original-06.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/original-07.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/original-08.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/original-09.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/original-10.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/review.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/source-1.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/source-2.png** — Temporary processing/review artifact; not live application logic.
- **tmp/pdfs/source-3.png** — Temporary processing/review artifact; not live application logic.

### tools/

- **tools/generate-furnicraft-erd.php** — Developer utility script for project asset/document work: generate-furnicraft-erd.php.
- **tools/optimize-brand-assets.py** — Developer utility script for project asset/document work: optimize-brand-assets.py.

### vite.config.js/

- **vite.config.js** — Project configuration or support file used by the application or developer tooling.

## Pangunahing sagot sa defense

Hinati ang system sa layers: routes ang URL mapping, controllers ang request flow, services ang business logic, models ang database access at views ang interface. Migrations ang nagtatala ng schema history. Ang room fitting ay visual estimate na nakadepende sa larawan at estimated dimensions, hindi physical measurement guarantee. External AI provider ang ginagamit kaya mahalaga ang configuration, privacy at spending controls.
