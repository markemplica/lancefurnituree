<aside class="recommendation-chat-widget" data-chat-widget data-chat-storage-key="furnicraft-chat-open-{{ $recommendation->id }}">
    <section class="recommendation-chat-panel chat-panel" data-chat-panel id="recommendation-chat-panel" aria-labelledby="recommendation-chat-title" role="dialog" hidden>
        <header class="recommendation-chat-head chat-head">
            <div>
                <h2 id="recommendation-chat-title">Ask about these matches</h2>
                <p>Some answers are handled locally. Questions needing an AI response may be sent with recommendation and catalog details to {{ \App\Services\AiProvider::label() }}, separately from room-photo consent. Avoid sharing sensitive personal information. <a href="{{ route('privacy-policy') }}">Privacy information</a>.</p>
            </div>
            <button class="recommendation-chat-close" type="button" data-chat-close aria-label="Close chat">×</button>
        </header>
        <div class="chat-log" data-chat-log aria-live="polite" aria-relevant="additions text">
            @forelse($recommendation->messages as $message)
                <div class="chat-message {{ $message->role }}"><strong>{{ $message->role === 'user' ? 'You' : 'FurniCraft' }}</strong>@if($message->role === 'assistant')<small class="d-block text-muted">{{ match($message->ai_source) { 'gemini' => 'Gemini response', 'catalog-rule' => 'Current catalog answer', 'local-fallback' => 'Local catalog fallback', default => $message->gemini_used ? 'AI-assisted response' : 'Local response' } }}</small>@endif<div>{{ $message->message }}</div></div>
            @empty
                <p class="chat-empty">Ask about material, finish, dimensions, price, or room suitability.</p>
            @endforelse
        </div>
        <form class="chat-compose recommendation-chat-compose" method="POST" action="{{ route('recommendations.chat',$recommendation) }}" data-chat-form>
            @csrf
            <label class="visually-hidden" for="recommendation-chat-message">Your question</label>
            <textarea class="form-control" id="recommendation-chat-message" name="message" rows="2" maxlength="1000" placeholder="Ask about a product or preference" required aria-describedby="recommendation-chat-hint">{{ old('message') }}</textarea>
            <div class="recommendation-chat-compose-footer"><small id="recommendation-chat-hint">Up to 1,000 characters</small><button class="btn btn-primary" type="submit">Ask FurniCraft</button></div>
        </form>
    </section>
    <button class="recommendation-chat-launcher" type="button" data-chat-toggle aria-label="Ask FurniCraft" aria-controls="recommendation-chat-panel" aria-expanded="false">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><path d="M20 11.5a7.5 7.5 0 0 1-7.5 7.5H6l-3 2v-5.5A7.5 7.5 0 1 1 20 11.5Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 11.5h8M8 14.5h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span>Ask FurniCraft</span>
    </button>
</aside>
