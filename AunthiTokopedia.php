<?php 
<!DOCTYPE html>
  <html>
require 'time'
require 'faraday'
require 'signet/oauth_2/client'
require 'ads_common/auth/base_handler'
require 'ads_common/errors'
module AdsCommon
  module Auth
    # Credentials class to handle OAuth2.0 authentication.
    class OAuth2Handler < AdsCommon::Auth::BaseHandler
      OAUTH2_CONFIG = {
          :authorization_uri =>
            'https://accounts.google.com/o/oauth2/auth',
          :token_credential_uri =>
            'https://accounts.google.com/o/oauth2/token'
      }
      DEFAULT_CALLBACK = 'urn:ietf:wg:oauth:2.0:oob'
     
      def initialize(config, scope)
        super(config)
        @scopes = []
        @scopes << scope unless scope.nil?
        additional_scopes = @config.read('authentication.oauth2_extra_scopes')
        @scopes += additional_scopes if additional_scopes.is_a?(Array)
        @client = nil
      end
      # Invalidates the stored token if the required credential has changed.
      def property_changed(prop, value)
        oauth2_keys = [:oauth2_client_id, :oauth2_client_secret, :oauth2_token]
        if oauth2_keys.include?(prop)
          @token, @client = nil, nil
        end
      end
      def handle_error(error)
        # TODO: Add support.
        get_logger().error(error)
        raise error
      end
    
      def auth_string(credentials)
        token = get_token(credentials)
        return ::Signet::OAuth2.generate_bearer_authorization_header(
            token[:access_token])
      end
      # Overrides base get_token method to account for the token expiration.
      def get_token(credentials = nil)
        token = super(credentials)
        token = refresh_token! if !@client.nil? && @client.expired?
        #token = refresh_token! if !@client.nil? && @client.expired?
        unless @client.nil?
          @client.issued_at = Time.parse(@client.issued_at) if @client.issued_at.class.name == 'String'
          token = refresh_token! if @client.expired?
        end
        return token
      end

      # Refreshes access token from refresh token.
      def refresh_token!()
        return nil if @token.nil? or @token[:refresh_token].nil?
        begin
          if @client.issued_at.is_a?(String)
            @client.issued_at = Time.parse(@client.issued_at)
          end
          @client.refresh!
        rescue Signet::AuthorizationError => e
          raise AdsCommon::Errors::AuthError.new("OAuth2 token refresh failed",
              e, (e.response.nil?) ? nil : e.response.body)
        end
        @token = token_from_client(@client)
        return @token
      end
      private
     
      def validate_credentials(credentials)
        if @scopes.empty?
          raise AdsCommon::Errors::AuthError, 'Scope is not specified.'
        end
        if credentials.nil?
          raise AdsCommon::Errors::AuthError, 'No credentials supplied.'
        end
        if credentials[:oauth2_client_id].nil?
          raise AdsCommon::Errors::AuthError,
              'Client id is not included in the credentials.'
        end
        if credentials[:oauth2_client_secret].nil?
          raise AdsCommon::Errors::AuthError,
              'Client secret is not included in the credentials.'
        end
        if credentials[:oauth2_token] &&
            !credentials[:oauth2_token].kind_of?(Hash)
          raise AdsCommon::Errors::AuthError,
              'OAuth2 token provided must be a Hash'
        end
      end
        code required
      
      def create_token(credentials)
        validate_credentials(credentials)
        @client ||= create_client(credentials)
        return create_token_from_credentials(credentials, @client) ||
            generate_access_token(credentials, @client)
      end
      def create_client(credentials)
        oauth_options = OAUTH2_CONFIG.merge({
            :client_id => credentials[:oauth2_client_id],
            :client_secret => credentials[:oauth2_client_secret],
            :scope => @scopes.join(' '),
            :redirect_uri => credentials[:oauth2_callback] || DEFAULT_CALLBACK,
            :state => credentials[:oauth2_state]
        }).reject {|k, v| v.nil?}
        return Signet::OAuth2::Client.new(oauth_options)
      end
     
      def create_token_from_credentials(credentials, client)
        oauth2_token_hash = credentials[:oauth2_token]
        if !oauth2_token_hash.nil? && oauth2_token_hash.kind_of?(Hash)
          token_data = AdsCommon::Utils.hash_keys_to_str(oauth2_token_hash)
          client.update_token!(token_data)
        end
        return token_from_client(client)
      end
     
      def generate_access_token(credentials, client)
        token = nil
        begin
          verification_code = credentials[:oauth2_verification_code]
          if verification_code.nil? || verification_code.empty?
            uri_options = {
              :access_type => credentials[:oauth2_access_type],
              :approval_prompt => credentials[:oauth2_approval_prompt]
            }.reject {|k, v| v.nil?}
            oauth_url = client.authorization_uri(uri_options)
            raise AdsCommon::Errors::OAuth2VerificationRequired.new(oauth_url)
          else
            client.code = verification_code
            proxy = @config.read('connection.proxy')
            connection = (proxy.nil?) ? nil : Faraday.new(:proxy => proxy)
            client.fetch_access_token!(:connection => connection)
            token = token_from_client(client)
          end
        rescue Signet::AuthorizationError => e
          raise AdsCommon::Errors::AuthError,
              'Authorization error occured: %s' % e
        end
        return token
      end
      # Create a token Hash from a client.
      def token_from_client(client)
        return nil if client.refresh_token.nil? && client.access_token.nil?
        return {
          :access_token => client.access_token,
          :refresh_token => client.refresh_token,
          :issued_at => client.issued_at,
          :expires_in => client.expires_in,
          :id_token => client.id_token
        }
      end
    end
  end
end
  </html>