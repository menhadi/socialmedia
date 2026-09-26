<label>Account label <span>optional</span><input name="display_name" maxlength="100" value="{{ $editing ? $account->display_name : old('display_name') }}" placeholder="A name to recognize this account"></label>
@if($platform==='instagram')
    <label>Token login method<select name="login_method">@foreach(['facebook'=>'Facebook Login (linked Page)','instagram'=>'Instagram Login'] as $value=>$label)<option value="{{ $value }}" @selected(($editing ? ($account->settings['login_method'] ?? 'facebook') : old('login_method','facebook'))===$value)>{{ $label }}</option>@endforeach</select></label>
    <label>Linked Facebook Page ID <span>optional</span><input name="facebook_page_id" inputmode="numeric" pattern="[0-9]{1,50}" maxlength="50" value="{{ $editing ? ($account->settings['facebook_page_id'] ?? '') : old('facebook_page_id') }}" placeholder="For accounts using Facebook Login"></label>
@endif
@if($platform==='whatsapp')
    <label>WhatsApp Business Account ID <span>optional</span><input name="business_account_id" inputmode="numeric" pattern="[0-9]{1,50}" maxlength="50" value="{{ $editing ? ($account->settings['business_account_id'] ?? '') : old('business_account_id') }}" placeholder="Business Account ID from Meta"></label>
@endif
<label>{{ $setup['token_label'] }} <span>optional</span><input name="access_token" type="password" maxlength="4096" autocomplete="new-password" placeholder="{{ $editing && $account->access_token ? 'Token saved; leave blank to keep it' : 'Add later or paste your API access token' }}"></label>
<p class="muted small">{{ $editing ? 'A blank token field keeps the saved token. Use Remove saved token below to clear it.' : 'You can save the account ID now and add credentials when you are ready.' }}</p>