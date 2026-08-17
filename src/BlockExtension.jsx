import React, { useState, useEffect, useCallback } from 'react';
import {
  reactExtension,
  useApi,
  AdminBlock,
  BlockStack,
  InlineStack,
  TextField,
  Select,
  Button,
  Banner,
  Text,
  Divider,
} from '@shopify/ui-extensions-react/admin';

// CONFIGURATION: Replace with your actual Laravel App URL or NGROK development tunnel URL
const APP_URL = 'https://your-shopify-upi-app.ngrok-free.app';

export default reactExtension('admin.product-details.block.render', () => <App />);

function App() {
  const api = useApi();
  const rawProductId = api.data.productId;
  
  // Extract integer product ID from Shopify GID (e.g. gid://shopify/Product/123456789 -> 123456789)
  const productId = rawProductId ? rawProductId.split('/').pop() : null;

  const [upiCode, setUpiCode] = useState('');
  const [upiStatus, setUpiStatus] = useState('Active');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [bannerMessage, setBannerMessage] = useState(null);
  const [bannerStatus, setBannerStatus] = useState('info'); // 'success' | 'critical' | 'info'

  // Fetch product UPI info from Laravel App DB
  const fetchUpiData = useCallback(async () => {
    if (!productId) {
      setBannerMessage('Unable to resolve Shopify Product ID.');
      setBannerStatus('critical');
      setLoading(false);
      return;
    }

    try {
      setLoading(true);
      const sessionToken = await api.sessionToken.get();
      
      const response = await fetch(`${APP_URL}/api/products/${productId}/upi`, {
        method: 'GET',
        headers: {
          'Authorization': `Bearer ${sessionToken}`,
          'Accept': 'application/json',
        },
      });

      if (!response.ok) {
        if (response.status === 404) {
          // Product exists in Shopify but not synced to Laravel yet
          setUpiCode('');
          setUpiStatus('Active');
          setBannerMessage('This product is not synced locally in the central Laravel database yet. Click Save to create.');
          setBannerStatus('info');
        } else {
          throw new Error(`Failed to load data (Status: ${response.status})`);
        }
      } else {
        const result = await response.json();
        if (result.success && result.product) {
          setUpiCode(result.product.upi_code || '');
          setUpiStatus(result.product.upi_status || 'Active');
          setBannerMessage(null);
        }
      }
    } catch (error) {
      console.error('Error fetching UPI details:', error);
      setBannerMessage('Could not retrieve UPI details from Laravel backend. Please check connection.');
      setBannerStatus('critical');
    } finally {
      setLoading(false);
    }
  }, [productId, api.sessionToken]);

  useEffect(() => {
    fetchUpiData();
  }, [fetchUpiData]);

  // Create or Update UPI code
  const handleSave = async () => {
    if (!upiCode.trim()) {
      setBannerMessage('UPI Code cannot be empty.');
      setBannerStatus('critical');
      return;
    }

    try {
      setSaving(true);
      setBannerMessage(null);
      const sessionToken = await api.sessionToken.get();

      const response = await fetch(`${APP_URL}/api/products/${productId}/upi`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${sessionToken}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          upi_code: upiCode,
          upi_status: upiStatus,
          updated_by: 'Shopify Admin Extension',
        }),
      });

      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.error || 'Failed to save UPI code.');
      }

      setBannerMessage('UPI Code saved and synced successfully!');
      setBannerStatus('success');
      
      // Refresh state
      if (result.product) {
        setUpiCode(result.product.upi_code || '');
        setUpiStatus(result.product.upi_status || 'Active');
      }
    } catch (error) {
      console.error('Error saving UPI:', error);
      setBannerMessage(`Error: ${error.message}`);
      setBannerStatus('critical');
    } finally {
      setSaving(false);
    }
  };

  // Clear UPI code
  const handleClear = async () => {
    try {
      setSaving(true);
      setBannerMessage(null);
      const sessionToken = await api.sessionToken.get();

      const response = await fetch(`${APP_URL}/api/products/${productId}/upi`, {
        method: 'DELETE',
        headers: {
          'Authorization': `Bearer ${sessionToken}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          updated_by: 'Shopify Admin Extension (Cleared)',
        }),
      });

      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.error || 'Failed to clear UPI code.');
      }

      setUpiCode('');
      setUpiStatus('Active');
      setBannerMessage('UPI Code cleared and synced successfully.');
      setBannerStatus('success');
    } catch (error) {
      console.error('Error clearing UPI:', error);
      setBannerMessage(`Error: ${error.message}`);
      setBannerStatus('critical');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <AdminBlock title="UPI Code Management">
        <BlockStack gap="small">
          <Text size="medium" tone="subdued">Loading UPI Management details...</Text>
        </BlockStack>
      </AdminBlock>
    );
  }

  return (
    <AdminBlock title="UPI Code Management">
      <BlockStack gap="medium">
        {bannerMessage && (
          <Banner tone={bannerStatus === 'critical' ? 'critical' : bannerStatus === 'success' ? 'success' : 'info'}>
            <Text>{bannerMessage}</Text>
          </Banner>
        )}

        <TextField
          label="UPI Code"
          value={upiCode}
          onChange={(val) => setUpiCode(val)}
          placeholder="e.g. UPI-12345-NEW"
        />

        <Select
          label="UPI Status"
          value={upiStatus}
          onChange={(val) => setUpiStatus(val)}
          options={[
            { label: 'Active', value: 'Active' },
            { label: 'Pending Review', value: 'Pending Review' },
            { label: 'Deprecated', value: 'Deprecated' },
          ]}
        />

        <Divider />

        <InlineStack inlineAlignment="end" gap="small">
          <Button
            onPress={handleClear}
            tone="critical"
            disabled={!upiCode || saving}
          >
            Clear UPI
          </Button>
          <Button
            onPress={handleSave}
            variant="primary"
            disabled={saving}
          >
            Save Changes
          </Button>
        </InlineStack>
      </BlockStack>
    </AdminBlock>
  );
}
