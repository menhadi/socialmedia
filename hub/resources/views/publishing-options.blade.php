@if($post->channel==='youtube')
    <div class="notice"><strong>YouTube video settings</strong><p>The saved title becomes the video title. Upload acceptance does not confirm processing or public visibility. Google may restrict unaudited apps to private uploads.</p></div>
    <label>Visibility<select name="options[privacy]" required>@foreach(['private'=>'Private','unlisted'=>'Unlisted','public'=>'Public'] as $key=>$label)<option value="{{ $key }}" @selected(old('options.privacy','private')===$key)>{{ $label }}</option>@endforeach</select></label>
    <label>Is this video made for children?<select name="options[made_for_kids]" required><option value="">Choose the correct audience</option><option value="0" @selected(old('options.made_for_kids')==='0')>No</option><option value="1" @selected(old('options.made_for_kids')==='1')>Yes</option></select></label>
@elseif($post->channel==='whatsapp')
    <div class="notice"><strong>WhatsApp recipient message</strong><p>This sends to one consenting recipient. It does not post to Status or Channels. API acceptance is not a delivery or read receipt.</p></div>
    <label>Recipient phone number<input name="options[recipient]" required inputmode="numeric" pattern="[1-9][0-9]{7,14}" value="{{ old('options.recipient') }}" placeholder="Country code and number, e.g. 919876543210"></label>
    <label class="checkbox"><input type="checkbox" name="options[consent]" value="1" required @checked(old('options.consent'))>This recipient has agreed to receive these messages.</label>
    <label>Message type<select name="options[mode]" required><option value="session" @selected(old('options.mode')==='session')>Saved message and media (active 24-hour service window)</option><option value="template" @selected(old('options.mode')==='template')>Approved text template</option></select></label>
    <label>Last customer message time (UTC, for service-window messages)<input name="options[last_inbound_at]" type="datetime-local" value="{{ old('options.last_inbound_at') }}"></label>
    <p class="muted small">Enter the actual last inbound message time. The 24-hour window is checked again when a scheduled message is sent.</p>
    <details><summary>Approved template settings</summary>
        <p>This sends the selected Meta-approved template instead of the saved draft body. Only text templates with body parameters are supported here. Preview the approved wording in Meta before confirming.</p>
        <label>Template name<input name="options[template_name]" value="{{ old('options.template_name') }}" placeholder="exam_update"></label>
        <label>Template language code<input name="options[template_language]" value="{{ old('options.template_language','en_US') }}" placeholder="en_US"></label>
        <label>Body parameter values, in order (one per line)<textarea name="options[template_values]" rows="3">{{ old('options.template_values') }}</textarea></label>
        <label class="checkbox"><input type="checkbox" name="options[template_confirm]" value="1" @checked(old('options.template_confirm'))>I checked the approved template wording and these parameter values.</label>
    </details>
@elseif($post->channel==='instagram')
    <p class="muted small">Instagram requires an image or video Reel. Images are converted to JPEG; use a square or landscape image (4:5 to 1.91:1). Meta retrieves the media through a temporary signed link. Caption links are not clickable. The server checks processing before publishing.</p>
@elseif($post->channel==='linkedin' || $post->channel==='x')
    <p class="muted small">Text, one image or one video are supported. Media processing is checked by the server before the post is submitted. {{ $post->channel==='x'?'Keep text within 280 weighted characters, including any link.':'Keep the message within 3,000 characters, including any link.' }}</p>
@endif
