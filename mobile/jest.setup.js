// NetInfo is a native module; its own mock reports "connected".
jest.mock('@react-native-community/netinfo', () => require('@react-native-community/netinfo/jest/netinfo-mock.js'));
