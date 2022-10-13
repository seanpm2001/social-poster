<?php
namespace verbb\socialposter\providers;

use verbb\socialposter\base\Provider;
use verbb\socialposter\helpers\SocialPosterHelper;

use Craft;

use League\OAuth2\Client\Provider\Facebook as FacebookProvider;

use Throwable;

class Facebook extends Provider
{
    // Public Methods
    // =========================================================================

    public function getName(): string
    {
        return 'Facebook';
    }

    public function getSettingsHtml(): ?string
    {
        $assetFieldOptions = SocialPosterHelper::getAssetFieldOptions();

        return Craft::$app->getView()->renderTemplate('social-poster/_providers/facebook/settings', [
            'provider' => $this,
            'account' => $this->account,
            'assetFieldOptions' => $assetFieldOptions,
        ]);
    }

    public function getDefaultOauthScope(): array
    {
        return [
            // API version 7.0+
            'public_profile',
            'email',
            'pages_manage_posts',
            'publish_to_groups',
            'pages_read_engagement',
            'pages_read_user_content',
        ];
    }

    public function getManagerUrl(): ?string
    {
        return 'https://developers.facebook.com/apps';
    }

    public function getScopeDocsUrl(): ?string
    {
        return 'https://developers.facebook.com/docs/facebook-login/permissions';
    }

    public function getOauthProviderConfig(): array
    {
        $config = parent::getOauthProviderConfig();

        if (empty($config['options']['graphApiVersion'])) {
            $config['options']['graphApiVersion'] = 'v3.0';
        }

        return $config;
    }

    public function getOauthProvider(): FacebookProvider
    {
        $config = $this->getOauthProviderConfig();

        return new FacebookProvider($config['options']);
    }

    public function getResponseUrl($data): ?string
    {
        if (isset($data['id'])) {
            return 'https://facebook.com/' . $data['id'];
        }

        return null;
    }

    public function sendPost($account, $content): array
    {
        try {
            $token = $account->getToken();
            $info = $this->getOauthProviderConfig();

            $pageOrGroupId = '';
            $endpoint = $content['endpoint'];
            $accessToken = $token->accessToken;

            if ($endpoint == 'page') {
                $pageOrGroupId = $endpoint = $content['pageId'];
            } else if ($endpoint == 'group') {
                $pageOrGroupId = $endpoint = $content['groupId'];
            }

            $fb = new \JanuSoftware\Facebook\Facebook([
                'app_id' => $info['options']['clientId'],
                'app_secret' => $info['options']['clientSecret'],
                'default_graph_version' => 'v2.10',
            ]);

            $client = Craft::createGuzzleClient([
                'base_uri' => 'https://graph.facebook.com/',
            ]);

            if ($pageOrGroupId) {
                // Get long-lived access token from the user access token
                $accessToken = $fb->getOAuth2Client()->getLongLivedAccessToken($accessToken);
                $fb->setDefaultAccessToken($accessToken);

                // Use long-lived access token to get a page access token which will never expire
                $response = $fb->sendRequest('GET', $pageOrGroupId, ['fields' => 'access_token'])->getDecodedBody();
                $accessToken = $response['access_token'];
                $fb->setDefaultAccessToken($accessToken);
            }

            $params = [
                'access_token' => $accessToken,
                'message' => $content['message'],
                'link' => $content['url'],
            ];

            // Only send the picture if there's content - otherwise will often fail due to API restrictions
            if (isset($content['picture']) && $content['picture']) {
                $params['picture'] = $content['picture'];
            }

            $response = $client->post($endpoint . '/feed', [
                'form_params' => $params,
            ]);

            return $this->getPostResponse($response);
        } catch (Throwable $e) {
            return $this->getPostExceptionResponse($e);
        }
    }
}
